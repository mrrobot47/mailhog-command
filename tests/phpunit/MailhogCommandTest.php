<?php

/**
 * Mailhog_Command: site checks, the container-state check, the compose/postfix commands and the mailhog_enabled flag.
 */
class MailhogCommandTest extends MailhogTestCase {

	const ENABLE = [
		'docker-compose -f docker-compose.yml  up -d mailhog',
		"docker-compose -f docker-compose.yml  exec -T  postfix postconf -e 'relayhost = mailhog:1025'",
		'docker-compose -f docker-compose.yml  restart postfix',
	];

	const DISABLE = [
		'docker-compose -f docker-compose.yml  stop mailhog',
		"docker-compose -f docker-compose.yml  exec -T  postfix postconf -e 'relayhost ='",
		'docker-compose -f docker-compose.yml  restart postfix',
	];

	const CHECK = [
		'docker-compose -f docker-compose.yml  config --services',
		'docker-compose -f docker-compose.yml  ps -q mailhog',
	];

	private function command() {
		return new Mailhog_Command();
	}

	public function test_enable_starts_mailhog_points_postfix_at_it_and_sets_the_flag() {
		$dir = $this->site();
		$this->global_auth();
		$this->compose();

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			}
		);

		$this->assertSame( array_merge( self::CHECK, self::ENABLE ), $this->runner->commands() );
		$this->assertSame( $dir, getcwd() );
		$this->assertSame( '1', $this->flag() );
		$this->assertStringContainsString( 'Mailhog enabled for example.test site', $this->stdout() );
	}

	public function test_enable_starts_a_stopped_container() {
		$this->site();
		$this->global_auth();
		$this->compose( 'false' );

		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			}
		);
		$this->assertSame( self::ENABLE, array_slice( $this->runner->commands(), -3 ) );
		$this->assertSame( '1', $this->flag() );
	}

	public function test_enable_refuses_a_running_mailhog_unless_forced() {
		$this->site();
		$this->global_auth();
		$this->compose( 'true' );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			},
			'Mailhog is already up.'
		);
		$this->assertSame( [], $this->runner->matching( '/ up -d | postconf /' ) );
		$this->assertSame( '0', $this->flag() );

		$this->runner->calls = [];
		$this->run_ok(
			function () {
				$this->command()->enable( [ 'example.test' ], [ 'force' => true ] );
			}
		);
		$this->assertSame( self::ENABLE, $this->runner->commands() );
		$this->assertSame( '1', $this->flag() );
	}

	/**
	 * @dataProvider subcommands
	 */
	public function test_subcommands_refuse_a_disabled_site( $subcommand ) {
		$this->site( [ 'site_enabled' => 0 ] );
		$this->global_auth();

		$this->assert_ee_error(
			function () use ( $subcommand ) {
				$this->command()->$subcommand( [ 'example.test' ], [] );
			},
			'Site example.test does not exist / is not enabled.'
		);
		$this->assertSame( [], $this->runner->commands() );
	}

	/**
	 * @dataProvider subcommands
	 */
	public function test_subcommands_refuse_a_site_without_a_mailhog_service( $subcommand ) {
		$this->site( [ 'site_type' => 'html' ] );
		$this->global_auth();
		$this->compose( null, "nginx\n" );

		$this->assert_ee_error(
			function () use ( $subcommand ) {
				$this->command()->$subcommand( [ 'example.test' ], [] );
			},
			'example.test site does not have support to enable/disable mailhog.'
		);
		$this->assertSame( [ self::CHECK[0] ], $this->runner->commands() );
	}

	public function subcommands() {
		return [
			'enable'  => [ 'enable' ],
			'disable' => [ 'disable' ],
			'status'  => [ 'status' ],
		];
	}

	public function test_subcommands_accept_a_trailing_slash() {
		$this->site();
		$this->compose();

		$this->run_ok(
			function () {
				$this->command()->status( [ 'example.test/' ], [] );
			}
		);
		$this->assertStringContainsString( 'Mailhog is DOWN for example.test site.', $this->stdout() );
	}

	public function test_disable_stops_mailhog_resets_the_relayhost_and_clears_the_flag() {
		$dir = $this->site( [ 'mailhog_enabled' => 1 ] );
		$this->compose( 'true' );

		$this->run_ok(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			}
		);

		$commands = $this->runner->commands();
		$this->assertSame( self::CHECK, array_slice( $commands, 0, 2 ) );
		$this->assertSame( self::DISABLE, array_slice( $commands, -3 ) );
		$this->assertSame( $dir, getcwd() );
		$this->assertSame( '0', $this->flag() );
		$this->assertStringContainsString( 'Mailhog disabled for example.test site', $this->stdout() );
	}

	/**
	 * @dataProvider down_states
	 */
	public function test_disable_refuses_when_mailhog_is_down( $state ) {
		$this->site();
		$this->compose( $state );

		$this->assert_ee_error(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			},
			'Mailhog is already down.'
		);
		$this->assertSame( [], $this->runner->matching( '/ stop | postconf | restart /' ) );
	}

	public function down_states() {
		return [
			'no container'      => [ null ],
			'stopped container' => [ 'false' ],
		];
	}

	/**
	 * @dataProvider status_states
	 */
	public function test_status_reports_the_container_state( $state, $message ) {
		$this->site();
		$this->compose( $state );

		$this->run_ok(
			function () {
				$this->command()->status( [ 'example.test' ], [] );
			}
		);
		$this->assertSame( "$message\n", $this->stdout() );
		$this->assertSame( [], $this->runner->matching( '/ up | stop | postconf | restart /' ) );
	}

	public function status_states() {
		return [
			'running'      => [ 'true', 'Mailhog is UP for example.test site.' ],
			'stopped'      => [ 'false', 'Mailhog is DOWN for example.test site.' ],
			'no container' => [ null, 'Mailhog is DOWN for example.test site.' ],
		];
	}

	/**
	 * MAILHOG-2: `http://<site>` passes auto_site_name() but is then looked up verbatim, so the site "does not exist".
	 *
	 * @group known-bug
	 * @group MAILHOG-2
	 */
	public function test_status_accepts_a_site_url_with_scheme() {
		$this->site();
		$this->compose();

		$this->run_ok(
			function () {
				$this->command()->status( [ 'http://example.test' ], [] );
			}
		);
		$this->assertStringContainsString( 'Mailhog is DOWN for example.test site.', $this->stdout() );
	}

	/**
	 * MAILHOG-3: enable ignores the results of compose up, postconf and the postfix restart, then reports success.
	 *
	 * @group known-bug
	 * @group MAILHOG-3
	 * @dataProvider enable_steps
	 */
	public function test_enable_fails_when_a_step_fails( $step ) {
		$this->site();
		$this->global_auth();
		$this->compose();
		$this->runner->on( '/' . preg_quote( $step, '/' ) . '$/', '', 1 );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			}
		);
		$this->assertSame( '0', $this->flag() );
	}

	public function enable_steps() {
		return [
			'compose up'      => [ ' up -d mailhog' ],
			'postconf'        => [ "postconf -e 'relayhost = mailhog:1025'" ],
			'postfix restart' => [ ' restart postfix' ],
		];
	}

	/**
	 * MAILHOG-3: disable has the same pattern: a failed stop or postconf still clears the flag and reports success.
	 *
	 * @group known-bug
	 * @group MAILHOG-3
	 * @dataProvider disable_steps
	 */
	public function test_disable_fails_when_a_step_fails( $step ) {
		$this->site( [ 'mailhog_enabled' => 1 ] );
		$this->compose( 'true' );
		$this->runner->on( '/' . preg_quote( $step, '/' ) . '$/', '', 1 );

		$this->assert_ee_error(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			}
		);
		$this->assertSame( '1', $this->flag() );
	}

	public function disable_steps() {
		return [
			'compose stop' => [ ' stop mailhog' ],
			'postconf'     => [ "postconf -e 'relayhost ='" ],
		];
	}

	/**
	 * MAILHOG-5: `enable --force` skips check_mailhog_available(), so a site without mailhog is "enabled".
	 *
	 * @group known-bug
	 * @group MAILHOG-5
	 */
	public function test_forced_enable_still_requires_a_mailhog_service() {
		$this->site( [ 'site_type' => 'html' ] );
		$this->global_auth();
		$this->compose( null, "nginx\n" );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [ 'force' => true ] );
			},
			'example.test site does not have support to enable/disable mailhog.'
		);
		$this->assertSame( [], $this->runner->matching( '/ up -d | postconf | restart /' ) );
		$this->assertSame( '0', $this->flag() );
	}

	/**
	 * MAILHOG-4: enable initialises the global admin-tools auth (proxy check, htpasswd, auth rows) before validating the site.
	 *
	 * @group known-bug
	 * @group MAILHOG-4
	 */
	public function test_enable_validates_the_site_before_touching_global_auth() {
		$this->site( [ 'site_enabled' => 0 ] );
		// A running proxy with matching ports; the auth init then stops at the htpasswd check (past it, it spawns `ee auth update`).
		$this->docker_on_path();
		$this->runner->on( "/^docker inspect -f '\\{\\{\\.State\\.Running\\}\\}' services_global-nginx-proxy_1$/", "true\n" );
		$this->runner->on( '/^docker inspect --format .*"80\/tcp"/', "80\n" );
		$this->runner->on( '/^docker inspect --format .*"443\/tcp"/', "443\n" );
		$this->runner->on( '/^docker exec services_global-nginx-proxy_1 /', '', 1 );

		$this->assert_ee_error(
			function () {
				$this->command()->enable( [ 'example.test' ], [] );
			},
			'Site example.test does not exist / is not enabled.'
		);
		$this->assertSame( [], $this->runner->commands() );
		$this->assertSame( [], EE\Tests\TestDB::rows( 'auth_users' ) );
	}

	/**
	 * MAILHOG-1: disable only looks at the container; with mailhog stopped outside ee, the flag and relayhost stay set.
	 *
	 * @group known-bug
	 * @group MAILHOG-1
	 */
	public function test_disable_resets_an_enabled_site_whose_container_is_stopped() {
		$this->site( [ 'mailhog_enabled' => 1 ] );
		$this->compose( 'false' );

		$this->run_ok(
			function () {
				$this->command()->disable( [ 'example.test' ], [] );
			}
		);
		$this->assertCount( 1, $this->runner->matching( "/postconf -e 'relayhost ='$/" ) );
		$this->assertSame( '0', $this->flag() );
	}
}
