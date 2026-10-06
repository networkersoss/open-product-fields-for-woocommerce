#!/usr/bin/env node
/**
 * Disposable runtime harness for the WAPF-PRODUCT-SUBSCRIPTION ledger row.
 *
 * Provisions a throwaway WordPress + WooCommerce clone under /tmp, installs the
 * untouched WooCommerce Subscriptions 9.2.0 source staged in the GPL scratchpad,
 * links this plugin worktree in read-only, then drives
 * bin/e2e-subscription-runtime.php through `wp eval-file` for every lifecycle
 * phase and copies the raw JSON artifacts into the repository.
 *
 *   node bin/e2e-subscription-runtime.mjs [--rebuild] [--keep-clone]
 *
 * Guarded: requires OPF_SUB_RT_ALLOW=1. The production WordPress install is
 * never read from or written to; only the disposable /tmp clone is mutated.
 */

import { spawnSync } from 'node:child_process';
import {
	cpSync,
	existsSync,
	mkdirSync,
	readdirSync,
	readFileSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve( dirname( fileURLToPath( import.meta.url ) ), '..' );
const CLONE = process.env.OPF_SUB_RT_CLONE || '/tmp/opf-subscriptions-wp';
const WP_URL = 'http://127.0.0.1:8322';
const STAGED_SUBSCRIPTIONS =
	'/home/followersya-5hqi7/ops/scratchpad/20261006-opf-gpl-sources/woocommerce-subscriptions';
const SOURCE_WP = process.env.OPF_SUB_RT_SOURCE_WP || '/home/followersya-5hqi7/opf-test/wordpress';
const RAW_ARTIFACTS = '/tmp/opf-sub-rt-artifacts';
const REPO_ARTIFACTS = join( ROOT, 'docs/compatibility/subscription-runtime-20261006' );
const FIXTURE = join( ROOT, 'bin/e2e-subscription-runtime.php' );
const PHASES = [ 'setup', 'cart', 'checkout', 'renewal', 'order-again', 'refund' ];

const log = [];
const run = ( command, args, options = {} ) => {
	const result = spawnSync( command, args, { encoding: 'utf8', ...options } );
	const line = `$ ${ command } ${args.join( ' ' )}\n${ result.stdout || '' }${ result.stderr || '' }`;
	log.push( line );
	if ( result.status !== 0 && ! options.allowFailure ) {
		process.stdout.write( line );
		throw new Error( `${ command } exited with ${ result.status }` );
	}
	return result;
};

const wp = ( ...args ) => run( 'wp', [ `--path=${ CLONE }`, ...args ] );

const guard = () => {
	if ( process.env.OPF_SUB_RT_ALLOW !== '1' ) {
		throw new Error( 'Set OPF_SUB_RT_ALLOW=1 to run the disposable subscription runtime proof.' );
	}
	if ( ! CLONE.startsWith( '/tmp/opf-subscriptions' ) ) {
		throw new Error( `Refusing clone path outside /tmp/opf-subscriptions*: ${ CLONE }` );
	}
	for ( const required of [ SOURCE_WP, STAGED_SUBSCRIPTIONS, FIXTURE ] ) {
		if ( ! existsSync( required ) ) {
			throw new Error( `Missing required path: ${ required }` );
		}
	}
};

const buildClone = () => {
	rmSync( CLONE, { recursive: true, force: true } );
	mkdirSync( CLONE, { recursive: true } );
	run( 'rsync', [
		'-a',
		'--exclude=wp-content/database/.ht.sqlite',
		'--exclude=wp-content/database/*.sqlite',
		'--exclude=wp-content/database/*.sqlite-*',
		`${ SOURCE_WP }/`,
		`${ CLONE }/`,
	] );

	// The source drop-in hard-codes its own plugin path and the source site URL.
	const dropin = join( CLONE, 'wp-content/db.php' );
	writeFileSync(
		dropin,
		readFileSync( dropin, 'utf8' ).replace(
			`'${ SOURCE_WP }/wp-content/plugins/sqlite-database-integration'`,
			"realpath( __DIR__ . '/plugins/sqlite-database-integration' )"
		)
	);
	const config = join( CLONE, 'wp-config.php' );
	writeFileSync( config, readFileSync( config, 'utf8' ).replace( /http:\/\/opf\.test/g, WP_URL ) );

	// This plugin is linked to the live worktree so the proof runs the exact
	// code under review; everything else in the clone is a private copy.
	const pluginLink = join( CLONE, 'wp-content/plugins/open-product-fields-for-woocommerce' );
	rmSync( pluginLink, { recursive: true, force: true } );
	symlinkSync( ROOT, pluginLink );
	cpSync( STAGED_SUBSCRIPTIONS, join( CLONE, 'wp-content/plugins/woocommerce-subscriptions' ), {
		recursive: true,
	} );
	rmSync( join( CLONE, 'wp-content/mu-plugins' ), { recursive: true, force: true } );

	wp(
		'core',
		'install',
		`--url=${ WP_URL }`,
		'--title=OPF Subscriptions Runtime',
		'--admin_user=admin',
		'--admin_password=admin',
		'--admin_email=admin@example.test',
		'--skip-email'
	);
	for ( const plugin of [ 'woocommerce', 'woocommerce-subscriptions', 'open-product-fields-for-woocommerce' ] ) {
		wp( 'plugin', 'activate', plugin );
	}
};

const runPhase = ( phase ) => {
	const result = run(
		'wp',
		[ `--path=${ CLONE }`, 'eval-file', FIXTURE ],
		{
			allowFailure: true,
			env: {
				...process.env,
				OPF_SUB_RT_ALLOW: '1',
				OPF_SUB_RT_PHASE: phase,
				OPF_SUB_RT_ARTIFACTS: RAW_ARTIFACTS,
			},
		}
	);
	const output = `${ result.stdout || '' }${ result.stderr || '' }`;

	for ( const line of output.split( '\n' ) ) {
		if ( /^phase=|^  FAIL |^SUCCESS /.test( line ) ) {
			process.stdout.write( `${ line }\n` );
		}
	}

	return { phase, status: result.status, output };
};

const collect = ( results ) => {
	mkdirSync( REPO_ARTIFACTS, { recursive: true } );
	const summary = { clone: CLONE, site: WP_URL, php: process.env.PHP || 'php', phases: {} };

	for ( const result of results ) {
		const file = join( RAW_ARTIFACTS, `${ result.phase }.json` );
		if ( ! existsSync( file ) ) {
			summary.phases[ result.phase ] = { status: result.status, artifact: null };
			continue;
		}
		const payload = JSON.parse( readFileSync( file, 'utf8' ) );
		cpSync( file, join( REPO_ARTIFACTS, `${ result.phase }.json` ) );
		summary.phases[ result.phase ] = {
			status: result.status,
			passed: payload.passed,
			failed: payload.failed,
			artifact: `docs/compatibility/subscription-runtime-20261006/${ result.phase }.json`,
		};
	}

	const state = join( RAW_ARTIFACTS, 'state.json' );
	if ( existsSync( state ) ) {
		cpSync( state, join( REPO_ARTIFACTS, 'state.json' ) );
	}
	summary.passed = Object.values( summary.phases ).reduce( ( total, p ) => total + ( p.passed || 0 ), 0 );
	summary.failed = Object.values( summary.phases ).reduce( ( total, p ) => total + ( p.failed || 0 ), 0 );
	summary.checks = readdirSync( RAW_ARTIFACTS ).filter( ( name ) => name.endsWith( '.json' ) ).sort();

	writeFileSync( join( REPO_ARTIFACTS, 'summary.json' ), `${ JSON.stringify( summary, null, 4 ) }\n` );
	writeFileSync( join( REPO_ARTIFACTS, 'command-log.txt' ), `${ log.join( '\n\n' ) }\n` );
	return summary;
};

const main = () => {
	guard();
	rmSync( RAW_ARTIFACTS, { recursive: true, force: true } );
	mkdirSync( RAW_ARTIFACTS, { recursive: true } );

	const rebuild = process.argv.includes( '--rebuild' ) || ! existsSync( join( CLONE, 'wp-config.php' ) );
	if ( rebuild ) {
		process.stdout.write( '--- provisioning disposable clone ---\n' );
		buildClone();
	} else {
		process.stdout.write( `--- reusing clone ${ CLONE } ---\n` );
	}

	run( 'wp', [ `--path=${ CLONE }`, 'plugin', 'list', '--fields=name,status,version' ] );

	const results = PHASES.map( runPhase );
	const summary = collect( results );

	process.stdout.write(
		`\nruntime checks: ${ summary.passed } passed, ${ summary.failed } failed\n` +
			`artifacts: ${ REPO_ARTIFACTS }\n`
	);

	if ( results.some( ( result ) => result.status !== 0 ) || summary.failed > 0 ) {
		process.exitCode = 1;
	}
};

main();
