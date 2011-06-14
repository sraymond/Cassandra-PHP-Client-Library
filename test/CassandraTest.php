<?php

require '../Cassandra.php';

class CassandraTest extends PHPUnit_Framework_TestCase {
	
	protected $servers;
	
	/**
	 * @var Cassandra
	 */
	protected $c;
	
	protected static $setupComplete = false;
	
	public function setup() {
		$this->servers = array(
			array(
				'host' => '127.0.0.1',
				'port' => 9160
			)
		);
		
		$this->c = Cassandra::createInstance($this->servers);
		
		if (!self::$setupComplete) {
			try {
				$this->c->dropKeyspace('CassandraTest');
			} catch (Exception $e) {}

			$this->c->setMaxCallRetries(5);
			$this->c->createKeyspace('CassandraTest', 1);
			$this->c->useKeyspace('CassandraTest');
			
			$this->c->createStandardColumnFamily(
				'CassandraTest',
				'user',
				array(
					array(
						'name' => 'name',
						'type' => Cassandra::TYPE_UTF8,
						'index-type' => Cassandra::INDEX_KEYS,
						'index-name' => 'NameIdx'
					),
					array(
						'name' => 'email',
						'type' => Cassandra::TYPE_UTF8
					),
					array(
						'name' => 'age',
						'type' => Cassandra::TYPE_UTF8
					)
				)
			);
			
			$this->c->createSuperColumnFamily(
				'CassandraTest',
				'cities',
				array(
					array(
						'name' => 'population',
						'type' => Cassandra::TYPE_UTF8
					),
					array(
						'name' => 'comment',
						'type' => Cassandra::TYPE_UTF8
					)
				),
				Cassandra::TYPE_UTF8,
				Cassandra::TYPE_UTF8,
				Cassandra::TYPE_UTF8,
				'Capitals supercolumn test',
				1000,
				1000,
				0.5
			);
			
			self::$setupComplete = true;
		} else {
			$this->c->useKeyspace('CassandraTest');
		}
	}
	
	public function tearDown() {
		unset($this->c);
	}
	
	/**
	 * @expectedException CassandraConnectionClosedException
	 */
	public function testGetClientThrowsExceptionIfConnectionClosed() {
		$connection = $this->c->getConnection();
		$client = $connection->getClient();
		
		if (!($client instanceof CassandraClient)) {
			$this->fail('Instance of CassandraClient expected');
		}
		
		$this->c->closeConnections();
		
		$connection->getClient();
	}
	
	public function testFramedOrBufferedTransportIsAvailable() {
		$framed = new CassandraConnection('127.0.0.1', 9160, true, 1000, 1000);
		$buffered = new CassandraConnection('127.0.0.1', 9160, false);
		
		if (!($framed->getTransport() instanceof TFramedTransport)) {
			$this->fail('Expected framed transport');
		}
		
		if (!($buffered->getTransport() instanceof TBufferedTransport)) {
			$this->fail('Expected buffered transport');
		}
	}
	
	public function testCanAuthenticateToKeyspace() {
		$this->c->useKeyspace('CassandraTest', 'admin', 'qwerty');
	}
	
	public function testClusterKnowsActiveKeyspace() {
		$this->assertEquals(
			$this->c->getCluster()->getCurrentKeyspace(),
			'CassandraTest'
		);
	}
	
	public function testClusterKnowsServerList() {
		$servers = array(
			array(
				'host' => '127.0.0.1',
				'port' => 9160,
				'use-framed-transport' => true,
				'send-timeout-ms' => null,
				'receive-timeout-ms' => null,
			)
		);
		
		$this->assertEquals(
			$this->c->getCluster()->getServers(),
			$servers
		);
	}
	
	public function testReturnsServerVersion() {
		$this->assertNotEmpty($this->c->getVersion());
	}
	
	/**
	 * @expectedException CassandraConnectionFailedException
	 */
	public function testClusterGetConnectionThrowsExceptionIfNoServersAdded() {
		$cluster = new CassandraCluster();
		
		$cluster->getConnection();
	}
	
	public function testClosedConnectionsAreRemovedFromCluster() {
		$connection = $this->c->getConnection();
		
		$connection->close();
		
		$this->c->getConnection();
	}
	
	/**
	 * @expectedException CassandraConnectionFailedException
	 */
	public function testExceptionIsThrownIfConnectionFails() {
		$servers = array(
			array(
				'host' => '127.0.0.1',
				'port' => 9161
			)
		);
		
		$cassandra = Cassandra::createInstance($servers, 'other');
		$cassandra->getConnection();
	}
	
	public function testGetInstanceReturnsInstanceByName() {
		$this->assertEquals(
			Cassandra::getInstance(),
			$this->c
		);
		
		$this->assertEquals(
			Cassandra::getInstance('main'),
			$this->c
		);
	}
	
	/**
	 * @expectedException CassandraInvalidRequestException
	 */
	public function testGetInstanceThrowsExceptionIfNameNotFound() {
		Cassandra::getInstance('foobar');
	}
	
	public function testUseKeyspaceCanSkipAuthOnSecondCall() {
		$this->c->useKeyspace('CassandraTest', 'admin', 'qwerty');
		$this->c->useKeyspace('CassandraTest');
	}
	
	public function testMaxCallRetriesCanBeSet() {
		$this->c->setMaxCallRetries(3);
		$this->c->setDefaultColumnCount(50);
		
		try {
			$this->c->call('foobar');
		} catch (Exception $e) {
			$this->assertEquals(
				$e->getMessage(),
				'Failed calling "foobar" the maximum of 3 times'
			);
		}
	}
	
	/**
	 * @expectedException CassandraInvalidRequestException
	 */
	public function testExceptionIsThrownIfKeyspaceNotSetOnCall() {
		$cassandra = Cassandra::createInstance($this->servers, 'other');
		
		$cassandra->call('get');
	}
	
	public function testReturnsKeyspaceDescription() {
		$info = $this->c->describeKeyspace();
		
		$this->assertEquals(
			$info->name,
			'CassandraTest'
		);
	}
	
	public function testReturnsKeyspaceSchema() {
		$info = $this->c->getKeyspaceSchema('CassandraTest', true);
		$this->c->getKeyspaceSchema(); // coverage
		
		$this->assertEquals(
			$info['user']['name'],
			'user'
		);
	}
	
	public function testEscapesSpecialTokens() {
		$this->assertEquals(
			Cassandra::escape('./:/,/-/|'),
			'\\./\\:/\\,/\\-/\\|'
		);
	}
	
	public function testStoresAndFetchesStandardColumns() {
		$this->c->set(
			'user.foobar',
			array(
				'email' => 'foobar@gmail.com',
				'name' => 'John Smith',
				'age' => 23
			)
		);
		
		$this->c->set(
			'user.'.'foo.bar',
			array(
				'email' => 'other@gmail.com',
				'name' => 'Jane Doe',
				'age' => 18
			)
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com',
				'name' => 'John Smith',
				'age' => 23
			),
			$this->c->get('user.foobar')
		);
		
		$this->assertEquals(
			array(
				'email' => 'other@gmail.com',
				'name' => 'Jane Doe',
				'age' => 18
			),
			$this->c->get('user.'.Cassandra::escape('foo.bar'))
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com'
			),
			$this->c->get('user.foobar:email')
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com',
				'age' => 23
			),
			$this->c->get('user.foobar:email,age')
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com',
				'age' => 23
			),
			$this->c->get('user.foobar:email, age')
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com',
				'name' => 'John Smith',
				'age' => 23
			),
			$this->c->get('user.foobar:age-name')
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com',
				'name' => 'John Smith',
				'age' => 23
			),
			$this->c->get('user.foobar:a-o')
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com',
				'age' => 23
			),
			$this->c->get('user.foobar:a-f')
		);
		
		$this->assertEquals(
			array(
				'email' => 'foobar@gmail.com',
				'age' => 23
			),
			$this->c->get('user.foobar:a-z|2')
		);
		
		
		$this->assertEquals(
			array(
				'age' => 23,
				'email' => 'foobar@gmail.com'
			),
			$this->c->get('user.foobar|2')
		);
		
		$this->assertEquals(
			array(
				'name' => 'John Smith',
				'email' => 'foobar@gmail.com',
			),
			$this->c->get('user.foobar:z-a|2R')
		);
		
		$this->assertEquals(
			array(
				'name' => 'John Smith',
				'email' => 'foobar@gmail.com',
			),
			$this->c->get('user.foobar|2R')
		);
	}
	
	public function testStoresAndFetchesSuperColumns() {
		$this->c->set(
			'cities.Estonia',
			array(
				'Tallinn' => array(
					'population' => '411 980',
					'comment' => 'Capital of Estonia',
					'size' => 'big'
				),
				'Tartu' => array(
					'population' => '98 589',
					'comment' => 'City of good thoughts',
					'size' => 'medium'
				)
			)
		);
		
		$this->assertEquals(
			array(
				'population' => '98 589',
				'comment' => 'City of good thoughts',
				'size' => 'medium'
			),
			$this->c->get('cities.Estonia.Tartu')
		);
		
		$this->assertEquals(
			array(
				'population' => '98 589',
				'size' => 'medium'
			),
			$this->c->get('cities.Estonia.Tartu:population,size')
		);
		
		$this->assertEquals(
			array(
				'Tallinn' => array(
					'population' => '411 980',
					'comment' => 'Capital of Estonia',
					'size' => 'big'
				),
				'Tartu' => array(
					'population' => '98 589',
					'comment' => 'City of good thoughts',
					'size' => 'medium'
				)
			),
			$this->c->get('cities.Estonia[]')
		);
	}
}