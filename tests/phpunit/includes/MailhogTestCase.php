<?php

use EE\ExitException;
use EE\Tests\TestCase;

/**
 * Base for mailhog tests: a site dir per test, a global auth (so enable() doesn't create one: that starts the proxy and
 * spawns `ee auth update`) and a dummy docker on PATH for EE_DOCKER::container_status().
 */
abstract class MailhogTestCase extends TestCase {

	private $path_backup;

	protected function setUp(): void {
		parent::setUp();
		$this->set_config( [ 'custom-compose' => null, 'skip-tty' => true ] );
		$this->path_backup = getenv( 'PATH' );
	}

	protected function tearDown(): void {
		putenv( 'PATH=' . $this->path_backup );
		parent::tearDown();
	}

	/**
	 * EE_DOCKER::container_status() first runs a real `which docker`; put a dummy docker on PATH so it passes.
	 * The dummy is never run: EE::exec()/launch() go to the fake runner.
	 */
	protected function docker_on_path() {
		$bin = $this->sandbox() . '/bin';
		mkdir( $bin );
		file_put_contents( "$bin/docker", "#!/bin/sh\nexit 1\n" );
		chmod( "$bin/docker", 0755 );
		putenv( "PATH=$bin:" . $this->path_backup );
	}

	/**
	 * Insert a WordPress site with its own directory.
	 *
	 * @return string The site directory.
	 */
	protected function site( array $fields = [] ) {
		$fields += [ 'site_url' => 'example.test', 'site_type' => 'wp' ];
		$dir     = $this->sandbox() . '/' . $fields['site_url'];
		mkdir( $dir );
		$this->insert_site( $fields + [ 'site_fs_path' => $dir ] );

		return $dir;
	}

	protected function global_auth() {
		EE::db()->table( 'auth_users' )->insert( [ 'site_url' => 'default_admin_tools', 'username' => 'easyengine', 'password' => 'secret' ] );
	}

	/**
	 * Script the site's compose services and the mailhog container state.
	 *
	 * @param string|null $state null: no container, else the `docker inspect` answer ('true' running, 'false' stopped).
	 */
	protected function compose( $state = null, $services = "nginx\nphp\ndb\nmailhog\npostfix\n" ) {
		$this->runner->on( '/ config --services$/', $services );
		if ( null === $state ) {
			$this->runner->on( '/ ps -q mailhog$/', '' );

			return;
		}
		$this->docker_on_path();
		$this->runner->on( '/ ps -q mailhog$/', "4f1c2a9b7e3d\n" );
		$this->runner->on( "/^docker inspect -f '\\{\\{\\.State\\.Running\\}\\}' 4f1c2a9b7e3d$/", "$state\n" );
	}

	/** @return string The mailhog_enabled column of $url. */
	protected function flag( $url = 'example.test' ) {
		foreach ( EE\Tests\TestDB::rows( 'sites' ) as $row ) {
			if ( $url === $row['site_url'] ) {
				return (string) $row['mailhog_enabled'];
			}
		}

		return null;
	}

	/** Run $fn and fail with the EE error message if it exits. */
	protected function run_ok( callable $fn ) {
		try {
			$fn();
		} catch ( ExitException $e ) {
			$this->fail( 'Unexpected EE::error(): ' . trim( $this->stderr() ) );
		}
	}
}
