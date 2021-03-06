<?php
namespace Coroq;
use Coroq\PDOSessionHandler\SessionException;

/**
 * Session handler that saves data into RDBMS via PDO
 *
 * By design, this class does not lock the session storage during read() and write().
 * Therefore concurrent access to the same session ID can cause race condition.
 * Note that this class throws an exception on error, and session_start() will throw an exception.
 */
class PDOSessionHandler implements \SessionHandlerInterface {
  /** @var \PDO */
  private $pdo;
  /** @var string */
  private $table_name;
  /** @var callable */
  private $getCurrentTime;
  /** @var float */
  private $per_session_cleanup_rate;

  /**
   * Constructor
   *
   * @param \PDO $pdo
   * @param string $table_name
   * @param array $options getCurrentTime => callable, per_session_cleanup_rate => float
   * @param callable $getCurrentTime
   * @param float $per_session_cleanup_rate
   */
  public function __construct($pdo, $table_name, array $options = []) {
    if (preg_match("#[^a-zA-Z0-9_]#", $table_name)) {
      throw new \DomainException("Invalid \$table_name.");
    }
    $options += [
      "getCurrentTime" => "time",
      "per_session_cleanup_rate" => 0.2,
    ];
    $this->pdo = $pdo;
    $this->table_name = $table_name;
    $this->getCurrentTime = $options["getCurrentTime"];
    $this->per_session_cleanup_rate = $options["per_session_cleanup_rate"];
  }

  /**
   * Initialize session
   *
   * Do nothing in this implementation.
   * @param string $save_path not used.
   * @param string $session_name not used.
   * @return true
   */
  public function open($save_path, $session_name) {
    return true;
  }

  /**
   * Read session data
   *
   * @param string $session_id The session ID
   * @return string An encoded string of the read data. If nothing was read, it must return an empty string.
   * @throws SessionException
   */
  public function read($session_id) {
    try {
      // TODO: support RDBMS without "limit" clause such as Oracle
      $statement = $this->pdo->prepare(sprintf(
        'select session_data from %s where session_id = ? order by id desc limit 1',
        $this->table_name
      ));
      if (!$statement) {
        throw new SessionException($this->pdo->errorInfo()[2]);
      }
      if (!$statement->execute([$session_id])) {
        throw new SessionException($statement->errorInfo()[2]);
      }
      $encoded_session_data = $statement->fetchColumn();
      if ($encoded_session_data === false) {
        return "";
      }
      $session_data = base64_decode($encoded_session_data, true);
      if ($session_data === false) {
        throw new SessionException("Session data of $session_id has been corrupted.");
      }
      return $session_data;
    }
    catch (\PDOException $exception) {
      throw new SessionException("", 0, $exception);
    }
  }

  /**
   * Write session data
   *
   * @param string $session_id
   * @param string $session_data 
   * @return true
   * @throws SessionException
   */
  public function write($session_id, $session_data) {
    try {
      // TODO: consider whether rollback is needed within a transaction
      $statement = $this->pdo->prepare(sprintf(
        'insert into %s (time_created, session_id, session_data) values (?, ?, ?)',
        $this->table_name
      ));
      if (!$statement) {
        throw new SessionException($this->pdo->errorInfo()[2]);
      }
      $now = call_user_func($this->getCurrentTime);
      $encoded_session_data = base64_encode($session_data);
      if (!$statement->execute([$now, $session_id, $encoded_session_data])) {
        throw new SessionException($statement->errorInfo()[2]);
      }
      $this->deletePreviousData($now, $session_id);
      return true;
    }
    catch (\PDOException $exception) {
      throw new SessionException("", 0, $exception);
    }
  }

  /**
   * Delete previous data of the specified session
   * @param int $time_created
   * @param string $session_id
   */
  private function deletePreviousData($time_created, $session_id) {
    if ($this->per_session_cleanup_rate < mt_rand(1, 100) / 100) {
      return;
    }
    // ignore errors because cleaning up is not a fatal operation.
    try {
      $statement = $this->pdo->prepare(sprintf(
        'delete from %s where time_created < ? and session_id = ?',
        $this->table_name
      ));
      if (!$statement) {
        return;
      }
      $statement->execute([$time_created, $session_id]);
    }
    catch (\PDOException $exception) {
      // do nothing
    }
  }

  /**
   * Close the session
   *
   * Do nothing in this implementation.
   * @return true
   */
  public function close() {
    return true;
  }

  /**
   * Destroy a session
   *
   * @param string $session_id
   * @return true
   * @throws SessionException
   */
  public function destroy($session_id) {
    try {
      $statement = $this->pdo->prepare(sprintf(
        'delete from %s where session_id = ?',
        $this->table_name
      ));
      if (!$statement) {
        throw new SessionException($this->pdo->errorInfo()[2]);
      }
      if (!$statement->execute([$session_id])) {
        throw new SessionException($statement->errorInfo()[2]);
      }
      return true;
    }
    catch (\PDOException $exception) {
      throw new SessionException("", 0, $exception);
    }
  }

  /**
   * Cleanup old sessions
   *
   * @param int $maxlifetime
   * @return true
   * @throws SessionException
   */
  public function gc($maxlifetime) {
    try {
      $statement = $this->pdo->prepare(sprintf(
        'delete from %s where time_created <= ?',
        $this->table_name
      ));
      if (!$statement) {
        throw new SessionException($this->pdo->errorInfo()[2]);
      }
      $expiration_time = call_user_func($this->getCurrentTime) - $maxlifetime;
      if (!$statement->execute([$expiration_time])) {
        throw new SessionException($statement->errorInfo()[2]);
      }
      return true;
    }
    catch (\PDOException $exception) {
      throw new SessionException("", 0, $exception);
    }
  }
}
