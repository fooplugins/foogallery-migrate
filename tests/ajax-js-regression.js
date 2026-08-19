'use strict';

const fs = require('fs');
const vm = require('vm');

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

const initPhp = fs.readFileSync('includes/class-init.php', 'utf8');
const viewPhp = fs.readFileSync('includes/views/view-migrate-tab-content.php', 'utf8');

// AJAX contract: nonce and capability failures must be JSON 403 responses.
assert(initPhp.includes("check_ajax_referer( 'foogallery_content_migrate', 'foogallery_content_migrate', false )"), 'AJAX nonce check must not die with a non-JSON response');
assert(/Invalid request[\s\S]{0,120}, 403 \);/.test(initPhp), 'invalid nonce must return JSON HTTP 403');
assert(/current_user_can\( 'manage_options' \)[\s\S]{0,160}Unauthorized[\s\S]{0,80}, 403 \);/.test(initPhp), 'missing capability must return JSON HTTP 403');
assert(initPhp.includes("'progress' => $progress"), 'successful AJAX response must include progress');
assert(initPhp.includes("'html' => $html"), 'successful AJAX response must include final HTML');
assert(/catch \( \\Throwable \$e \)[\s\S]{0,240},\s*500/.test(initPhp), 'scan failures must return a generic JSON HTTP 500');

// Replace embedded PHP expressions with inert string literals and syntax-check the actual script.
const scriptMatch = viewPhp.match(/<script>([\s\S]*?)<\/script>/);
assert(scriptMatch, 'content view must contain a script block');
const executableScript = scriptMatch[1].replace(/<\?php[\s\S]*?\?>/g, '"test message"');
new vm.Script(executableScript, { filename: 'view-migrate-tab-content.inline.js' });

// Control flow: only successful incomplete batches recurse, and retries retain reset semantics.
assert(/if \(progress\.complete\)[\s\S]*?return;[\s\S]*?setTimeout\(function\(\) \{\s*scanContentBatch\(false\);/.test(viewPhp), 'only incomplete successful batches may schedule resume');
assert((viewPhp.match(/stopContentScan\(message, reset\)/g) || []).length === 3, 'both JSON and transport failures must stop with the correct reset mode');
assert(viewPhp.includes("scanInProgress = false;"), 'stop and completion paths must clear in-progress state');
assert(viewPhp.includes("dataType: \"json\""), 'batch requests must require JSON');
assert(!/error:[\s\S]{0,250}location\.reload/.test(viewPhp), 'transport failure must remain resumable without forced reload');

console.log('PASS: AJAX contract and JavaScript syntax/control-flow regression tests');
