<?php
// SQL/storage DOUBLE ONLY. It executes no MySQL and proves no engine/isolation semantics.
namespace {
    if (!function_exists('wp_salt')) {
        function wp_salt($scheme = 'auth') { return 'test-only-server-secret-not-a-production-key-0123456789'; }
    }
}
namespace FooPlugins\FooGalleryMigrate\Tests {
    use FooPlugins\FooGalleryMigrate\LegacyTransactionConnection;
    use FooPlugins\FooGalleryMigrate\StorageAuthority;
    use FooPlugins\FooGalleryMigrate\StorageMaintenanceGate;
    class DurableScanDb extends LegacyTransactionConnection {
        public $prefix = 'wp_';
        public $base_prefix = 'wp_';
        public $rows = array();
        public $raw_reads = 0;
        public $raw_bytes = 0;
        public $sha_bytes = 0;
        public $queries = array();
        public $fault = '';
        public $autocommit = '1';
        public $ambient_transaction = false;
        public $idle_query_failure = false;
        public function __construct($rows = array()) { $this->rows = $rows; }
        public function is_current() { return true; }
        public function prepare($sql, $args) { $args = is_array($args) ? $args : array_slice(func_get_args(), 1); return $sql . ' /*SCANARGS ' . base64_encode(serialize($args)) . ' */'; }
        public function esc_like($value) { return addcslashes($value, '_%\\'); }
        private function args($sql) {
            preg_match_all('/\/\*SCANARGS ([A-Za-z0-9+\/=]+) \*\//', $sql, $m);
            return array_map(function($v) { return unserialize(base64_decode($v)); }, $m[1]);
        }
        public function get_var($sql) {
            $this->queries[] = $sql; $this->last_error = '';
            if ('SELECT @@SESSION.autocommit' === $sql) { return $this->autocommit; }
            $all = $this->args($sql); $args = $all ? $all[0] : array();
            if (0 === strpos($sql, 'SHOW TABLES')) { return null; }
            if (false !== strpos($sql, 'option_name IN')) {
                foreach ($args as $name) { if (isset($this->rows[$name])) { return '1'; } }
                return null;
            }
            if (false !== strpos($sql, 'option_name LIKE')) {
                foreach ($this->rows as $name => $raw) { if (0 === strpos($name, StorageMaintenanceGate::WRITER_PREFIX)) { return '1'; } }
                return null;
            }
            $raw = isset($args[0], $this->rows[$args[0]]) ? $this->rows[$args[0]] : null;
            if (0 === strpos($sql, 'SELECT LENGTH')) { return null === $raw ? null : (string) strlen($raw); }
            if (0 === strpos($sql, "SELECT CONCAT('v:', option_value)")) {
                if (null === $raw || strlen($raw) !== $args[1]) { return null; }
                ++$this->raw_reads; $this->raw_bytes += strlen($raw); return 'v:' . $raw;
            }
            if (0 === strpos($sql, "SELECT CONCAT('v:'")) {
                $cap = false !== strpos($sql, '2048') ? 2048 : 512;
                return null === $raw ? null : 'v:' . (strlen($raw) <= $cap ? $raw : '');
            }
            throw new \LogicException('Unexpected read in SQL double: ' . $sql);
        }
        public function query($sql) {
            $this->queries[] = $sql; $this->last_error = '';
            if ('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' === $sql) {
                if ($this->ambient_transaction || $this->idle_query_failure) { $this->last_error = 'Transaction characteristic change refused'; return false; }
                return 0;
            }
            $all = $this->args($sql); $a = $all[0];
            $insert = 0 === strpos($sql, 'INSERT');
            $update = 0 === strpos($sql, 'UPDATE');
            $name = $update ? $a[1] : $a[0];
            $control = StorageAuthority::CONTROL_OPTION === $name;
            if ($control && 'before' === $this->fault) { $this->fault = ''; throw new \RuntimeException('Injected pre-CAS interruption'); }
            if ($control && 'source' === $this->fault) { $this->fault = ''; $this->rows[FOOGALLERY_MIGRATE_OPTION_DATA] = serialize(array('changed' => true)); }
            if ($control && 'cas' === $this->fault) { $this->fault = ''; return 0; }
            if (false !== strpos($sql, 'source_option')) {
                $g = $all[1]; $raw = $this->rows[FOOGALLERY_MIGRATE_OPTION_DATA];
                $this->sha_bytes += strlen($raw);
                if (hash('sha256', $raw) !== $g[1]) { return 0; }
            }
            if (false !== strpos($sql, 'maintenance_owner')) {
                $g = $all[count($all) - 1];
                if (!isset($this->rows[$g[0]]) || $this->rows[$g[0]] !== $g[1] || time() >= $g[2]) { return 0; }
                foreach ($this->rows as $key => $value) { if (0 === strpos($key, StorageMaintenanceGate::WRITER_PREFIX)) { return 0; } }
            }
            if ($insert) {
                if (isset($this->rows[$name])) { return 0; }
                $this->rows[$name] = $a[1];
            } else {
                $old = $update ? $a[2] : $a[1];
                if (!isset($this->rows[$name]) || $this->rows[$name] !== $old) { return 0; }
                if ($update) { $this->rows[$name] = $a[0]; } else { unset($this->rows[$name]); }
            }
            if ($control && 'after' === $this->fault) { $this->fault = ''; throw new \RuntimeException('Injected lost post-CAS response'); }
            return 1;
        }
    }
}
