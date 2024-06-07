<?php

class TransactionManager
{
    private $isTransactionStarted = false;
    private static $instance;

    public function start()
    {
        global $wpdb;

        if (!$this->isTransactionStarted) {
            $wpdb->query('BEGIN');
            $this->isTransactionStarted = true;
        }
    }

    public function commit()
    {
        global $wpdb;
		global $blclog;
		$blclog->debug('Starten des DB-Commits.');

        $this->start();

        try {
            $wpdb->query('COMMIT');
			$blclog->debug('Commit ausgeführt.');
            $this->isTransactionStarted = false;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
			$blclog->debug('Commit fehlgeschlagen; Rollback.');
            $this->isTransactionStarted = false;
        }
    }

    static public function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new TransactionManager();
        }

        return self::$instance;
    }
}
