<?php
/**
 * Base class for the models over FlyAffiliate's custom tables.
 *
 * @package FlyAffiliate
 */

namespace FlyAffiliate\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use FlyAffiliate\Utilities\Money;

/**
 * One row of one custom table, plus the queries that find them.
 *
 * A subclass declares its table suffix and its column map. Everything else —
 * casting, dirty tracking, insert versus update, the query builder — comes from
 * here, and every statement it produces is prepared.
 *
 * The column map is also the allow-list: a column that is not declared cannot be
 * written, ordered by, or filtered on, so a request parameter can never reach
 * the SQL as an identifier.
 *
 * @since FLYAFFILIATE_SINCE
 */
abstract class BaseModel {

	/**
	 * Table name without the `$wpdb->prefix`.
	 *
	 * @var string
	 */
	protected static string $table = '';

	/**
	 * Column name => type. Types: `int`, `float`, `money`, `string`, `datetime`.
	 *
	 * @var array<string, string>
	 */
	protected static array $columns = [];

	/**
	 * Columns whose value is set once, when the row is created.
	 *
	 * @var string[]
	 */
	protected static array $created_at_columns = [ 'created_at' ];

	/**
	 * Columns refreshed on every write.
	 *
	 * @var string[]
	 */
	protected static array $updated_at_columns = [ 'updated_at' ];

	/**
	 * The row, cast to PHP types.
	 *
	 * @var array<string, mixed>
	 */
	protected array $data = [];

	/**
	 * Columns changed since the row was loaded.
	 *
	 * @var array<string, bool>
	 */
	protected array $changes = [];

	/**
	 * Construct a model from a row, an array, or nothing.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param object|array|null $row Row to hydrate from.
	 */
	public function __construct( $row = null ) {
		if ( null === $row ) {
			return;
		}

		foreach ( (array) $row as $column => $value ) {
			if ( isset( static::$columns[ $column ] ) ) {
				$this->data[ $column ] = $this->cast( $column, $value );
			}
		}
	}

	/**
	 * The fully qualified table name.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return string
	 */
	public static function get_table(): string {
		global $wpdb;

		// `FlyAffiliate_Plugin::wpdb_table_shortcuts()` puts each table on $wpdb at
		// `init` priority 1. Falling back to the prefix keeps models usable before
		// that runs — during activation, and in a unit test that has not booted.
		$shortcut = static::$table;

		return isset( $wpdb->{$shortcut} ) ? (string) $wpdb->{$shortcut} : $wpdb->prefix . static::$table;
	}

	/**
	 * The column map.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, string>
	 */
	public static function get_columns(): array {
		return static::$columns;
	}

	/**
	 * Read a column.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $column  Column name.
	 * @param mixed  $fallback Value to return when the column is not set.
	 *
	 * @return mixed
	 */
	public function get( string $column, $fallback = null ) {
		return $this->data[ $column ] ?? $fallback;
	}

	/**
	 * Write a column, if it is one this model has.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Value.
	 *
	 * @return static
	 */
	public function set( string $column, $value ): self {
		if ( ! isset( static::$columns[ $column ] ) ) {
			return $this;
		}

		$cast = $this->cast( $column, $value );

		if ( ! array_key_exists( $column, $this->data ) || $this->data[ $column ] !== $cast ) {
			$this->data[ $column ]    = $cast;
			$this->changes[ $column ] = true;
		}

		return $this;
	}

	/**
	 * Write several columns.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $values Column => value.
	 *
	 * @return static
	 */
	public function fill( array $values ): self {
		foreach ( $values as $column => $value ) {
			$this->set( $column, $value );
		}

		return $this;
	}

	/**
	 * The row id, or 0 when the row has not been saved.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int
	 */
	public function get_id(): int {
		return (int) $this->get( 'id', 0 );
	}

	/**
	 * Whether this model refers to a row that exists.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function exists(): bool {
		return $this->get_id() > 0;
	}

	/**
	 * The row as an array.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Insert or update the row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int The row id, or 0 when the write failed.
	 */
	public function save(): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		foreach ( static::$updated_at_columns as $column ) {
			if ( isset( static::$columns[ $column ] ) ) {
				$this->data[ $column ]    = $now;
				$this->changes[ $column ] = true;
			}
		}

		if ( $this->exists() ) {
			return $this->update_row();
		}

		foreach ( static::$created_at_columns as $column ) {
			if ( isset( static::$columns[ $column ] ) && empty( $this->data[ $column ] ) ) {
				$this->data[ $column ] = $now;
			}
		}

		$values = $this->prepare_values( array_diff_key( $this->data, [ 'id' => true ] ) );

		if ( [] === $values ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FlyAffiliate's own custom table; no WordPress API reaches it. The query is prepared and its identifiers come from the model's declared column map.
		$inserted = $wpdb->insert( static::get_table(), $values, $this->formats_for( array_keys( $values ) ) );

		if ( false === $inserted ) {
			return 0;
		}

		$this->data['id'] = (int) $wpdb->insert_id;
		$this->changes    = [];

		return $this->get_id();
	}

	/**
	 * Delete the row.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return bool
	 */
	public function delete(): bool {
		global $wpdb;

		if ( ! $this->exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FlyAffiliate's own custom table; no WordPress API reaches it. The query is prepared and its identifiers come from the model's declared column map.
		$deleted = $wpdb->delete( static::get_table(), [ 'id' => $this->get_id() ], [ '%d' ] );

		if ( false === $deleted ) {
			return false;
		}

		$this->data    = [];
		$this->changes = [];

		return true;
	}

	/**
	 * Find one row by id.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param int $id Row id.
	 *
	 * @return static|null
	 */
	public static function find( int $id ): ?self {
		if ( $id <= 0 ) {
			return null;
		}

		return static::find_by( [ 'id' => $id ] );
	}

	/**
	 * Find the first row matching a set of equality conditions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $conditions Column => value.
	 *
	 * @return static|null
	 */
	public static function find_by( array $conditions ): ?self {
		global $wpdb;

		[ $where, $values ] = static::build_where( $conditions );

		if ( '' === $where ) {
			return null;
		}

		$table = static::get_table();
		$sql   = "SELECT * FROM {$table} WHERE {$where} LIMIT 1";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own custom table; no WordPress API reaches it. The query is prepared and its identifiers come from the model's declared column map.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ) );

		return null === $row ? null : new static( $row );
	}

	/**
	 * Query rows.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args {
	 *     Query arguments.
	 *
	 *     @type array  $where       Column => value, or column => array of values for an IN clause.
	 *     @type array  $search      `[ 'term' => string, 'columns' => string[], 'in' => array ]`: a LIKE match on any of
	 *                               the columns, OR-ed with `column IN (values)` for every entry of `in`.
	 *     @type string $after       Only rows whose `$date_column` is on or after this `Y-m-d H:i:s` value.
	 *     @type string $before      Only rows whose `$date_column` is on or before this value.
	 *     @type string $date_column Column the `after`/`before` bounds apply to. Default `created_at`.
	 *     @type string $orderby     Column to order by. Must be a declared column. Default `id`.
	 *     @type string $order       `ASC` or `DESC`. Default `DESC`.
	 *     @type int    $per_page    Rows per page. -1 for every row. Default 20.
	 *     @type int    $page        1-based page number. Default 1.
	 * }
	 *
	 * @return static[]
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			[
				'where'       => [],
				'search'      => [],
				'after'       => '',
				'before'      => '',
				'date_column' => 'created_at',
				'orderby'     => 'id',
				'order'       => 'DESC',
				'per_page'    => 20,
				'page'        => 1,
			]
		);

		[ $where, $values ] = static::build_conditions( $args );

		$table   = static::get_table();
		$orderby = static::sanitize_column( (string) $args['orderby'], 'id' );
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$sql     = "SELECT * FROM {$table}";

		if ( '' !== $where ) {
			$sql .= " WHERE {$where}";
		}

		$sql .= " ORDER BY {$orderby} {$order}";

		$per_page = (int) $args['per_page'];

		if ( $per_page > 0 ) {
			$page     = max( 1, (int) $args['page'] );
			$sql     .= ' LIMIT %d OFFSET %d';
			$values[] = $per_page;
			$values[] = ( $page - 1 ) * $per_page;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own custom table; no WordPress API reaches it. The query is prepared and its identifiers come from the model's declared column map.
		$rows = [] === $values ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

		return array_map(
			static function ( $row ) {
				return new static( $row );
			},
			(array) $rows
		);
	}

	/**
	 * Count rows.
	 *
	 * Takes either the same arguments as {@see query()} — `where`, `search`,
	 * `after`, `before` — or, for the common case, a bare column => value map.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Query arguments, or a column => value map.
	 *
	 * @return int
	 */
	public static function count( array $args = [] ): int {
		global $wpdb;

		if ( [] === array_intersect_key(
			$args, [
				'where' => 1,
				'search' => 1,
				'after' => 1,
				'before' => 1,
			]
		) ) {
			$args = [ 'where' => $args ];
		}

		[ $where, $values ] = static::build_conditions( $args );

		$table = static::get_table();
		$sql   = "SELECT COUNT(*) FROM {$table}";

		if ( '' !== $where ) {
			$sql .= " WHERE {$where}";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- FlyAffiliate's own custom table; no WordPress API reaches it. The query is prepared and its identifiers come from the model's declared column map.
		return (int) ( [] === $values ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, $values ) ) );
	}

	/**
	 * Update the row this model was loaded from.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @return int The row id, or 0 when nothing was written.
	 */
	protected function update_row(): int {
		global $wpdb;

		$changed = array_intersect_key( $this->data, $this->changes );

		unset( $changed['id'] );

		if ( [] === $changed ) {
			return $this->get_id();
		}

		$values = $this->prepare_values( $changed );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- FlyAffiliate's own custom table; no WordPress API reaches it. The query is prepared and its identifiers come from the model's declared column map.
		$updated = $wpdb->update(
			static::get_table(),
			$values,
			[ 'id' => $this->get_id() ],
			$this->formats_for( array_keys( $values ) ),
			[ '%d' ]
		);

		if ( false === $updated ) {
			return 0;
		}

		$this->changes = [];

		return $this->get_id();
	}

	/**
	 * Build the WHERE clause for a full set of query arguments.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array $args Query arguments as accepted by {@see query()}.
	 *
	 * @return array{0: string, 1: array} The clause and its ordered values.
	 */
	protected static function build_conditions( array $args ): array {
		global $wpdb;

		[ $where, $values ] = static::build_where( (array) ( $args['where'] ?? [] ) );

		$clauses = '' === $where ? [] : [ $where ];
		$search  = (array) ( $args['search'] ?? [] );
		$term    = trim( (string) ( $search['term'] ?? '' ) );
		$columns = array_filter(
			(array) ( $search['columns'] ?? [] ),
			static function ( $column ) {
				return isset( static::$columns[ $column ] );
			}
		);

		if ( '' !== $term && ( [] !== $columns || [] !== (array) ( $search['in'] ?? [] ) ) ) {
			$like  = '%' . $wpdb->esc_like( $term ) . '%';
			$parts = [];

			foreach ( $columns as $column ) {
				$parts[]  = "{$column} LIKE %s";
				$values[] = $like;
			}

			// Matches found elsewhere (a user table lookup, say) join the OR.
			foreach ( (array) ( $search['in'] ?? [] ) as $column => $ids ) {
				if ( ! isset( static::$columns[ $column ] ) ) {
					continue;
				}

				$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );

				if ( [] === $ids ) {
					continue;
				}

				$parts[] = "{$column} IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$values  = array_merge( $values, $ids );
			}

			// A term nothing can match must match nothing, not everything.
			$clauses[] = [] === $parts ? '1 = 0' : '( ' . implode( ' OR ', $parts ) . ' )';
		}

		$date_column = static::sanitize_column( (string) ( $args['date_column'] ?? 'created_at' ), 'created_at' );

		if ( isset( static::$columns[ $date_column ] ) ) {
			if ( ! empty( $args['after'] ) ) {
				$clauses[] = "{$date_column} >= %s";
				$values[]  = (string) $args['after'];
			}

			if ( ! empty( $args['before'] ) ) {
				$clauses[] = "{$date_column} <= %s";
				$values[]  = (string) $args['before'];
			}
		}

		return [ implode( ' AND ', $clauses ), $values ];
	}

	/**
	 * Build a prepared WHERE clause from equality and IN conditions.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $conditions Column => value, or column => array of values.
	 *
	 * @return array{0: string, 1: array} The clause and its ordered values.
	 */
	protected static function build_where( array $conditions ): array {
		$clauses = [];
		$values  = [];

		foreach ( $conditions as $column => $value ) {
			if ( ! isset( static::$columns[ $column ] ) ) {
				continue;
			}

			$format = static::format_for( $column );

			if ( is_array( $value ) ) {
				if ( [] === $value ) {
					// An empty IN () matches nothing; say so rather than emitting invalid SQL.
					$clauses[] = '1 = 0';
					continue;
				}

				$placeholders = implode( ', ', array_fill( 0, count( $value ), $format ) );
				$clauses[]    = "{$column} IN ({$placeholders})";
				$values       = array_merge( $values, array_values( $value ) );
				continue;
			}

			if ( null === $value ) {
				$clauses[] = "{$column} IS NULL";
				continue;
			}

			$clauses[] = "{$column} = {$format}";
			$values[]  = $value;
		}

		return [ implode( ' AND ', $clauses ), $values ];
	}

	/**
	 * Restrict a column name to one this model declares.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $column   Candidate column name.
	 * @param string $fallback Column to use when the candidate is not declared.
	 *
	 * @return string
	 */
	protected static function sanitize_column( string $column, string $fallback ): string {
		return isset( static::$columns[ $column ] ) ? $column : $fallback;
	}

	/**
	 * The `$wpdb` placeholder for a column.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $column Column name.
	 *
	 * @return string
	 */
	protected static function format_for( string $column ): string {
		switch ( static::$columns[ $column ] ?? 'string' ) {
			case 'int':
				return '%d';
			case 'float':
				return '%f';
			default:
				// Money is bound as a string so the decimal reaches MySQL exactly as computed.
				return '%s';
		}
	}

	/**
	 * Placeholders for a list of columns, in order.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string[] $columns Column names.
	 *
	 * @return string[]
	 */
	protected function formats_for( array $columns ): array {
		return array_map( [ static::class, 'format_for' ], $columns );
	}

	/**
	 * Convert model values into the forms the columns store.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param array<string, mixed> $values Column => value.
	 *
	 * @return array<string, mixed>
	 */
	protected function prepare_values( array $values ): array {
		$prepared = [];

		foreach ( $values as $column => $value ) {
			if ( ! isset( static::$columns[ $column ] ) ) {
				continue;
			}

			$prepared[ $column ] = 'money' === static::$columns[ $column ] && null !== $value
				? Money::for_db( $value )
				: $value;
		}

		return $prepared;
	}

	/**
	 * Cast a stored value to its PHP type.
	 *
	 * @since FLYAFFILIATE_SINCE
	 *
	 * @param string $column Column name.
	 * @param mixed  $value  Stored value.
	 *
	 * @return mixed
	 */
	protected function cast( string $column, $value ) {
		if ( null === $value ) {
			return null;
		}

		switch ( static::$columns[ $column ] ?? 'string' ) {
			case 'int':
				return (int) $value;
			case 'float':
			case 'money':
				return (float) $value;
			default:
				return (string) $value;
		}
	}
}
