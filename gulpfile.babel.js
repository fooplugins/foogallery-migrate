/**
 * Gulpfile.
 *
 * @author Brad Vincent
 */

const config = require( './wpgulp.config.js' );

const gulp = require( 'gulp' ); // Gulp of-course.

// Utility related plugins.
const shell = require( 'gulp-shell' ); // used to perform composer install
const wpPot = require( 'gulp-wp-pot' ); // For generating the .pot file.
const sort = require( 'gulp-sort' ); // Recommended to prevent unnecessary changes in pot-file.
const plumber = require( 'gulp-plumber' ); // Prevent pipe breaking caused by errors from gulp plugins.
const merge = require( 'merge-stream' );
const { defaults, isObjectLike } = require( 'lodash' );
const path = require( 'path' );

/**
 * Custom Error Handler.
 *
 * @param {Error} err
 */
const errorHandler = err => console.error( err );

const filesObj = ( obj, defaultOptions ) => {
	let opt = defaults( defaultOptions, {
		allowEmpty: true, process: function( src ) {
			return src;
		}
	} );
	if ( isObjectLike( obj ) ) {
		return {
			options: defaults( isObjectLike( obj.options ) ? obj.options : {}, opt ),
			files: isObjectLike( obj.files ) ? obj.files : obj
		};
	}
	return { options: opt, files: obj };
};

const filesTask = ( taskName, defaultOptions, done ) => {
	if ( !isObjectLike( config[ taskName ] ) ) {
		return done();
	}

	let root = filesObj( config[ taskName ], defaultOptions ),
		names = Object.keys( root.files );

	if ( 0 === names.length ) {
		return done();
	}

	let tasks = names.map( function( name ) {
		let task = filesObj( root.files[ name ], root.options );
		let src = gulp.src( task.files, { allowEmpty: task.options.allowEmpty } ).pipe( plumber( errorHandler ) ),
			file = {
				path: name, dir: path.dirname( name ), name: path.basename( name )
			};
		return task.options.process( src, file, task.options, task.files );
	} );

	return merge( tasks );
};

/**
 * WP POT Translation File Generator.
 *
 * This task does the following:
 * 1. Gets the source of all the PHP files
 * 2. Sort files in stream by path or any custom sort comparator
 * 3. Applies wpPot with the variable set at the top of this file
 * 4. Generate a .pot file of i18n that can be used for l10n to build .mo file
 */
gulp.task( 'translate', ( done ) => {
	return filesTask( 'translate', {
		allowEmpty: true,
		domain: null,
		package: null,
		bugReport: 'https://fooplugins.com',
		lastTranslator: 'Brad Vincent <brad@fooplugins.com>',
		team: 'FooPlugins <info@fooplugins.com>',
		process: ( src, file, opt ) => {
			return src.pipe( sort() )
				.pipe( wpPot( {
					domain: opt.domain,
					package: opt.package,
					bugReport: opt.bugReport,
					lastTranslator: opt.lastTranslator,
					team: opt.team
				} ) )
				.pipe( gulp.dest( file.path ) );
		}
	}, done );
} );

const zip = require( 'gulp-zip' ),
	buildInclude = [ '**/*', '!package*.json', '!./{node_modules,node_modules/**/*}', '!./{dist,dist/**/*}', '!./{src,src/**/*}', '!fs-config.json', '!composer.json', '!composer.lock', '!wpgulp.config.js', '!gulpfile.babel.js' ],
	packageJSON = require( './package.json' ), fileName = packageJSON.name, fileVersion = packageJSON.version;

/**
 * Used to generate the plugin zip file that will be uploaded the Freemius.
 * Generates a zip file based on the name and version found within the package.json
 *
 * usage : gulp zip
 *
 */
gulp.task( 'zip', function() {
	return gulp.src( buildInclude, { base: './' } )
		.pipe( zip( fileName + '.v' + fileVersion + '.zip' ) )
		.pipe( gulp.dest( 'dist/' ) );
} );

//runs composer install for deployment
gulp.task( 'composer-install-deploy', shell.task( [ 'composer install --prefer-dist --optimize-autoloader --no-dev' ] ) );

gulp.task( 'default', gulp.series( 'translate', 'zip' ) );
