<?php
use Coroq\PDOSessionHandler as Handler;
use Coroq\PDOSessionHandler\SessionException;
use Mockery as Mock;

class PDOSessionHandlerTest extends \PHPUnit_Framework_TestCase {
  const TEST_SESSION_ID = "test_session_id";
  const TEST_SESSION_DATA = 'a:1:{s:4:"data";s:19:"This is a test data";}';
  const TEST_TABLE_NAME = "test_sessions";

  public function tearDown() {
    Mock::close();
  }

  public function testOpen() {
    $this->assertTrue((new Handler(null, self::TEST_TABLE_NAME))->open("", ""));
  }

  public function testCanReadSessionData() {
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->once()
      ->with([self::TEST_SESSION_ID])
      ->andReturn(true)
      ->shouldReceive("fetchColumn")
      ->once()
      ->with()
      ->andReturn(base64_encode(self::TEST_SESSION_DATA));
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->with(sprintf('select session_data from %s where session_id = ? order by id desc limit 1', self::TEST_TABLE_NAME))
      ->andReturn($statement);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME);
    $this->assertEquals(self::TEST_SESSION_DATA, $handler->read(self::TEST_SESSION_ID));
  }

  public function testReadThrowsAnExceptionIfCouldNotPrepareStatement() {
    $error_message = "pdo->prepare() failed.";
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andReturn(false)
      ->shouldReceive("errorInfo")
      ->once()
      ->with()
      ->andReturn(["HY000", "9999", $error_message]);
    try {
      $handler = new Handler($pdo, self::TEST_TABLE_NAME);
      $handler->read(self::TEST_SESSION_ID);
      $this->fail();
    }
    catch (SessionException $exception) {
      $this->assertEquals($error_message, $exception->getMessage());
    }
  }

  public function testReadThrowsAnExceptionIfCouldNotExecuteStatement() {
    $error_message = "statement->execute() failed.";
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->once()
      ->with([self::TEST_SESSION_ID])
      ->andReturn(false)
      ->shouldReceive("errorInfo")
      ->once()
      ->with()
      ->andReturn(["HY000", "9999", $error_message]);
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andReturn($statement);
    try {
      $handler = new Handler($pdo, self::TEST_TABLE_NAME);
      $handler->read(self::TEST_SESSION_ID);
      $this->fail();
    }
    catch (SessionException $exception) {
      $this->assertEquals($error_message, $exception->getMessage());
    }
  }

  public function testReadThrowsAnExceptionIfPDOExceptionArised() {
    $error_message = "pdo exception.";
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andThrow(new \PDOException($error_message));
    try {
      $handler = new Handler($pdo, self::TEST_TABLE_NAME);
      $handler->read(self::TEST_SESSION_ID);
      $this->fail();
    }
    catch (SessionException $exception) {
      $this->assertEquals($error_message, $exception->getPrevious()->getMessage());
    }
  }

  public function testReadReturnsEmptyStringIfNoSessionData() {
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->once()
      ->andReturn(true)
      ->shouldReceive("fetchColumn")
      ->once()
      ->andReturn(false);
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andReturn($statement);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME);
    $this->assertEquals("", $handler->read(self::TEST_SESSION_ID));
  }

  public function testReadThrowsAnExceptionIfSessionDataWasCorrupted() {
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->once()
      ->andReturn(true)
      ->shouldReceive("fetchColumn")
      ->once()
      ->andReturn("#" . base64_encode(self::TEST_SESSION_DATA));
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andReturn($statement);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME);
    try {
      $handler = new Handler($pdo, self::TEST_TABLE_NAME);
      $handler->read(self::TEST_SESSION_ID);
      $this->fail();
    }
    catch (SessionException $exception) {
      $this->assertEquals(
        sprintf("Session data of %s has been corrupted.", self::TEST_SESSION_ID),
        $exception->getMessage()
      );
    }
  }

  public function testCanWriteSessionData() {
    $now = 123456789;
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->with([$now, self::TEST_SESSION_ID, base64_encode(self::TEST_SESSION_DATA)])
      ->once()
      ->andReturn(true);
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->with(sprintf('insert into %s (time_created, session_id, session_data) values (?, ?, ?)', self::TEST_TABLE_NAME))
      ->andReturn($statement);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME, function() use ($now) {
      return $now;
    }, 0);
    $this->assertTrue($handler->write(self::TEST_SESSION_ID, self::TEST_SESSION_DATA));
  }

  public function testCanCleanupOnWritingSessionData() {
    $now = 123456789;
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->with([$now, self::TEST_SESSION_ID, base64_encode(self::TEST_SESSION_DATA)])
      ->once()
      ->andReturn(true);
    $delete_statement = Mock::mock("PDOStatement");
    $delete_statement
      ->shouldReceive("execute")
      ->with([$now, self::TEST_SESSION_ID])
      ->once()
      ->andReturn(true);
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->with(sprintf('insert into %s (time_created, session_id, session_data) values (?, ?, ?)', self::TEST_TABLE_NAME))
      ->andReturn($statement)
      ->once()
      ->ordered();
    $pdo
      ->shouldReceive("prepare")
      ->with(sprintf('delete from %s where time_created < ? and session_id = ?', self::TEST_TABLE_NAME))
      ->andReturn($delete_statement)
      ->once()
      ->ordered();
    $handler = new Handler($pdo, self::TEST_TABLE_NAME, function() use ($now) {
      return $now;
    }, 1);
    $this->assertTrue($handler->write(self::TEST_SESSION_ID, self::TEST_SESSION_DATA));
  }

  public function testWriteThrowsAnExceptionIfCouldNotPrepareStatement() {
    $error_message = "pdo->prepare() failed.";
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andReturn(false)
      ->shouldReceive("errorInfo")
      ->once()
      ->with()
      ->andReturn(["AAA", "BBB", $error_message]);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME);
    try {
      $handler->write(self::TEST_SESSION_ID, self::TEST_SESSION_DATA);
      $this->fail();
    }
    catch (SessionException $exception) {
      $this->assertEquals($error_message, $exception->getMessage());
    }
  }

  public function testWriteThrowsAnExceptionIfCouldNotExecuteStatement() {
    $error_message = "statement->execute() failed.";
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->once()
      ->andReturn(false)
      ->shouldReceive("errorInfo")
      ->once()
      ->with()
      ->andReturn(["AAA", "BBB", $error_message]);
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andReturn($statement);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME);
    try {
      $handler->write(self::TEST_SESSION_ID, self::TEST_SESSION_DATA);
      $this->fail();
    }
    catch (SessionException $exception) {
      $this->assertEquals($error_message, $exception->getMessage());
    }
  }

  public function testWriteThrowsAnExceptionIfPDOExceptionArised() {
    $error_message = "pdo exception.";
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->andThrow(new \PDOException($error_message));
    $handler = new Handler($pdo, self::TEST_TABLE_NAME);
    try {
      $handler->write(self::TEST_SESSION_ID, self::TEST_SESSION_DATA);
      $this->fail();
    }
    catch (SessionException $exception) {
      $this->assertEquals($error_message, $exception->getPrevious()->getMessage());
    }
  }

  public function testClose() {
    $this->assertTrue((new Handler(null, self::TEST_TABLE_NAME))->close());
  }

  public function testDestroy() {
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->with([self::TEST_SESSION_ID])
      ->once()
      ->andReturn(true);
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->with(sprintf('delete from %s where session_id = ?', self::TEST_TABLE_NAME))
      ->andReturn($statement);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME);
    $this->assertTrue($handler->destroy(self::TEST_SESSION_ID));
  }

  public function testGC() {
    $now = 123456789;
    $maxlifetime = 60 * 60 * 4;
    $statement = Mock::mock("PDOStatement");
    $statement
      ->shouldReceive("execute")
      ->with([$now - $maxlifetime])
      ->once()
      ->andReturn(true);
    $pdo = Mock::mock("PDO");
    $pdo
      ->shouldReceive("prepare")
      ->once()
      ->with(sprintf('delete from %s where time_created <= ?', self::TEST_TABLE_NAME))
      ->andReturn($statement);
    $handler = new Handler($pdo, self::TEST_TABLE_NAME, function() use ($now) {
      return $now;
    });
    $this->assertTrue($handler->gc($maxlifetime));
  }
}
