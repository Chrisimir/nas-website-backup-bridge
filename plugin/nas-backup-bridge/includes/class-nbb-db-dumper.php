<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resumable pure-PHP SQL dumper (no exec / mysqldump needed).
 *
 * One gzip file per table: db/NNNN-<table>.sql.gz, each self-contained
 * (DROP + CREATE + INSERTs). Files may contain several gzip members because
 * they are appended to across requests; gzip/zcat/Python read them fine.
 *
 * Restore:  zcat db/*.sql.gz | mysql <database>
 */
class NBB_Db_Dumper {

	const BATCH_ROWS     = 500;
	const MAX_STMT_BYTES = 524288; // ~512 KB per INSERT statement.

	/** @var string */
	private $dir;
	/** @var array */
	private $state;
	/** @var callable|null */
	private $persist;

	/**
	 * @param string        $dir     Job directory.
	 * @param array         $state   Dumper state.
	 * @param callable|null $persist Called with the state after every batch, so an
	 *                               interrupted request can resume without duplicates.
	 */
	public function __construct( $dir, array $state, $persist = null ) {
		$this->dir     = $dir;
		$this->state   = $state;
		$this->persist = $persist;
	}

	private function persist() {
		if ( $this->persist ) {
			call_user_func( $this->persist, $this->state );
		}
	}

	private static function size_of( $file ) {
		clearstatcache( true, $file );
		return file_exists( $file ) ? (int) filesize( $file ) : 0;
	}

	/** Drop anything written after the last persisted batch (interrupted request). */
	private function truncate_to_checkpoint() {
		$cur = $this->state['cur'];
		if ( ! $cur ) {
			return;
		}
		$file = $this->dir . '/db/' . $cur['file'];
		if ( self::size_of( $file ) > $cur['bytes'] ) {
			$fh = fopen( $file, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $fh ) {
				ftruncate( $fh, $cur['bytes'] );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	public function state() {
		return $this->state;
	}

	/** Initial state: list tables (and views) to dump. */
	public static function init_state( $all_tables ) {
		global $wpdb;
		$like = $all_tables ? '%' : $wpdb->esc_like( $wpdb->base_prefix ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES WHERE `Tables_in_' . DB_NAME . '` LIKE %s', $like ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! is_array( $rows ) || ( empty( $rows ) && $wpdb->last_error ) ) {
			// Fallback for unusual DB names: filter in PHP.
			$rows = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
		}
		$tables = array();
		$views  = array();
		foreach ( (array) $rows as $row ) {
			$name = $row[0];
			if ( ! $all_tables && ! NBB_Util::starts_with( $name, $wpdb->base_prefix ) ) {
				continue;
			}
			if ( isset( $row[1] ) && 'VIEW' === strtoupper( $row[1] ) ) {
				$views[] = $name;
			} else {
				$tables[] = $name;
			}
		}
		sort( $tables, SORT_STRING );
		sort( $views, SORT_STRING );
		return array(
			'tables' => $tables,
			'views'  => $views,
			'idx'    => 0,
			'cur'    => null,
			'rows'   => 0,
			'done'   => false,
		);
	}

	private static function q( $ident ) {
		return '`' . str_replace( '`', '``', $ident ) . '`';
	}

	private function esc( $value ) {
		global $wpdb;
		$dbh = $wpdb->dbh;
		if ( $dbh instanceof mysqli ) {
			return mysqli_real_escape_string( $dbh, $value );
		}
		return strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"'"    => "\\'",
				'"'    => '\\"',
				"\x1a" => '\\Z',
			)
		);
	}

	private function file_for( $index, $table ) {
		$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $table );
		return $this->dir . '/db/' . sprintf( '%04d', $index ) . '-' . $safe . '.sql.gz';
	}

	private function write( $file, $data ) {
		$gz = gzopen( $file, 'ab6' );
		if ( ! $gz ) {
			throw new RuntimeException( 'No se puede escribir ' . basename( $file ) );
		}
		gzwrite( $gz, $data );
		gzclose( $gz );
	}

	/** Prepare per-table cursor and write the schema. */
	private function start_table( $table ) {
		global $wpdb;
		$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . self::q( $table ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $create || empty( $create[1] ) ) {
			throw new RuntimeException( 'SHOW CREATE TABLE falló para ' . $table . ': ' . $wpdb->last_error );
		}
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::q( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$cols    = array();
		$binary  = array();
		foreach ( (array) $columns as $c ) {
			$cols[]   = $c['Field'];
			$binary[] = (bool) preg_match( '/blob|binary|^bit/i', $c['Type'] );
		}
		$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . self::q( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$pk   = array();
		foreach ( (array) $keys as $k ) {
			$pk[ (int) $k['Seq_in_index'] ] = $k['Column_name'];
		}
		ksort( $pk );
		$pk = array_values( $pk );

		$mode = 'offset';
		if ( 1 === count( $pk ) ) {
			foreach ( (array) $columns as $c ) {
				if ( $c['Field'] === $pk[0] && preg_match( '/int/i', $c['Type'] ) ) {
					$mode = 'keyset';
				}
			}
		}

		$file = $this->file_for( $this->state['idx'], $table );
		if ( file_exists( $file ) ) {
			unlink( $file ); // A previous attempt was interrupted before finishing.
		}
		$header  = "-- NAS Backup Bridge SQL dump\n-- Table: {$table}\n";
		$header .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
		$header .= 'DROP TABLE IF EXISTS ' . self::q( $table ) . ";\n" . $create[1] . ";\n\n";
		$this->write( $file, $header );

		$this->state['cur'] = array(
			'table'  => $table,
			'file'   => basename( $file ),
			'cols'   => $cols,
			'binary' => $binary,
			'pk'     => $pk,
			'mode'   => $mode,
			'last'   => null,
			'offset' => 0,
			'rows'   => 0,
			'bytes'  => self::size_of( $file ),
		);
		$this->persist();
	}

	private function fetch_batch( array $cur, $limit ) {
		global $wpdb;
		$t = self::q( $cur['table'] );
		if ( 'keyset' === $cur['mode'] ) {
			$pk  = self::q( $cur['pk'][0] );
			$sql = "SELECT * FROM $t";
			if ( null !== $cur['last'] ) {
				$sql .= " WHERE $pk > '" . $this->esc( (string) $cur['last'] ) . "'";
			}
			$sql .= " ORDER BY $pk ASC LIMIT " . (int) $limit;
		} else {
			$order = '';
			if ( $cur['pk'] ) {
				$order = ' ORDER BY ' . implode( ', ', array_map( array( __CLASS__, 'q' ), $cur['pk'] ) );
			}
			$sql = "SELECT * FROM $t$order LIMIT " . (int) $cur['offset'] . ', ' . (int) $limit;
		}
		$rows = $wpdb->get_results( $sql, ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( null === $rows && $wpdb->last_error ) {
			throw new RuntimeException( 'Error leyendo ' . $cur['table'] . ': ' . $wpdb->last_error );
		}
		return (array) $rows;
	}

	private function value_sql( $v, $is_binary ) {
		if ( null === $v ) {
			return 'NULL';
		}
		if ( $is_binary ) {
			return '' === $v ? "''" : '0x' . bin2hex( $v );
		}
		return "'" . $this->esc( (string) $v ) . "'";
	}

	/** Dump one batch of the current table. Returns false when the table is finished. */
	private function dump_batch() {
		$cur  = $this->state['cur'];
		$rows = $this->fetch_batch( $cur, self::BATCH_ROWS );
		if ( empty( $rows ) ) {
			return false;
		}
		$prefix = 'INSERT INTO ' . self::q( $cur['table'] ) . ' (' . implode( ', ', array_map( array( __CLASS__, 'q' ), $cur['cols'] ) ) . ') VALUES ';
		$out    = '';
		$stmt   = '';
		$pk_pos = 'keyset' === $cur['mode'] ? array_search( $cur['pk'][0], $cur['cols'], true ) : false;

		foreach ( $rows as $row ) {
			$vals = array();
			foreach ( $row as $i => $v ) {
				$vals[] = $this->value_sql( $v, ! empty( $cur['binary'][ $i ] ) );
			}
			$tuple = '(' . implode( ',', $vals ) . ')';
			if ( '' === $stmt ) {
				$stmt = $prefix . $tuple;
			} elseif ( strlen( $stmt ) + strlen( $tuple ) > self::MAX_STMT_BYTES ) {
				$out .= $stmt . ";\n";
				$stmt = $prefix . $tuple;
			} else {
				$stmt .= ',' . $tuple;
			}
			if ( false !== $pk_pos ) {
				$cur['last'] = $row[ $pk_pos ];
			}
		}
		if ( '' !== $stmt ) {
			$out .= $stmt . ";\n";
		}
		$path = $this->dir . '/db/' . $cur['file'];
		$this->write( $path, $out );
		$cur['bytes'] = self::size_of( $path );

		$count            = count( $rows );
		$cur['offset']   += $count;
		$cur['rows']     += $count;
		$this->state['rows'] += $count;
		$this->state['cur']   = $cur;
		$this->persist();

		global $wpdb;
		if ( is_array( $wpdb->queries ) ) {
			$wpdb->queries = array(); // Avoid memory growth when SAVEQUERIES is on.
		}
		return $count >= self::BATCH_ROWS;
	}

	private function dump_views() {
		global $wpdb;
		if ( empty( $this->state['views'] ) ) {
			return;
		}
		$file = $this->file_for( 9999, 'views' );
		$sql  = "-- Views\nSET NAMES utf8mb4;\n";
		foreach ( $this->state['views'] as $view ) {
			$row = $wpdb->get_row( 'SHOW CREATE VIEW ' . self::q( $view ), ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( $row && ! empty( $row[1] ) ) {
				$create = preg_replace( '/DEFINER=`[^`]*`@`[^`]*`\s*/i', '', $row[1] );
				$sql   .= 'DROP VIEW IF EXISTS ' . self::q( $view ) . ";\n" . $create . ";\n";
			}
		}
		$this->write( $file, $sql );
	}

	/**
	 * Work until the deadline.
	 *
	 * @param float $deadline microtime(true) value to stop at.
	 * @return bool true when the whole database is dumped.
	 */
	public function run( $deadline ) {
		if ( ! is_dir( $this->dir . '/db' ) ) {
			wp_mkdir_p( $this->dir . '/db' );
		}
		$this->truncate_to_checkpoint();
		while ( microtime( true ) < $deadline && NBB_Util::memory_ok() ) {
			if ( $this->state['idx'] >= count( $this->state['tables'] ) ) {
				$this->dump_views();
				$this->state['done'] = true;
				return true;
			}
			if ( null === $this->state['cur'] ) {
				$this->start_table( $this->state['tables'][ $this->state['idx'] ] );
			}
			if ( ! $this->dump_batch() ) {
				$this->state['cur'] = null;
				$this->state['idx']++;
				$this->persist();
			}
		}
		return false;
	}
}
