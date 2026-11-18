<?php
/**
 * Bounded member-directory queries shared by admin UIs and compatibility APIs.
 *
 * This service owns filtering, role-aware projections, and pagination. Rendering
 * and browser interaction remain in admin/js/manage-members.js.
 */

namespace App\Services;

require_once __DIR__ . '/EducationLabels.php';

use InvalidArgumentException;
use PDO;
use PDOStatement;
use RuntimeException;

final class MemberDirectoryService
{
    public const DEFAULT_PAGE_SIZE = 50;
    public const MAX_PAGE_SIZE = 200;

    private const PRIVILEGED_ROLES = ['super_admin', 'school_admin', 'info_dept', 'hr_dept'];
    private const ID_CARD_ROLES = ['super_admin', 'school_admin', 'hr_dept'];

    private const FULL_COLUMNS = [
        'id', 'member_code', 'registration_type', 'member_type', 'status',
        'age_group', 'current_section', 'student_name', 'father_name',
        'grandfather_name', 'baptismal_name', 'gender', 'phone_number',
        'alt_phone_number', 'guardian_name', 'guardian_phone1',
        'guardian_phone2', 'city', 'sub_city', 'woreda', 'mender',
        'block_number', 'house_number', 'work_profession', 'education_level',
        'student_photo_path', 'membership_tier', 'created_at',
    ];

    private const NEW_MEMBERS_COLUMNS = [
        'id', 'member_code', 'registration_type', 'member_type', 'status',
        'age_group', 'current_section', 'student_name', 'father_name',
        'grandfather_name', 'baptismal_name', 'gender', 'phone_number',
        'alt_phone_number', 'guardian_name', 'guardian_phone1',
        'guardian_phone2', 'city', 'sub_city', 'woreda', 'mender',
        'block_number', 'house_number', 'work_profession', 'education_level',
        'student_photo_path', 'membership_tier', 'registered_at', 'created_at',
        // H1: promotion prefill needs the birth date (upgrade keeps it).
        'dob_ec_day', 'dob_ec_month', 'dob_ec_year',
    ];

    private const MANAGER_COLUMNS = [
        'id', 'member_code', 'registration_type', 'member_type', 'status',
        'student_name', 'father_name',
    ];

    private const PICKER_COLUMNS = [
        'id', 'member_code', 'student_name', 'father_name',
    ];

    private const EDUCATION_COLUMNS = [
        'id', 'member_code', 'student_name', 'father_name', 'status',
        'age_group', 'current_section',
    ];

    private const ID_CARD_COLUMNS = [
        'id', 'member_code', 'student_name', 'father_name',
        'registration_type', 'member_type', 'id_card_status',
        'id_card_generated_at',
    ];

    private const ARCHIVE_COLUMNS = [
        'id', 'member_code', 'student_name', 'father_name',
        'current_section', 'age_group', 'archive_type', 'archive_reason', 'archived_at',
    ];

    private PDO $connection;
    private bool $supportsFullText;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
        $this->supportsFullText = $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * @return array{members:array<int,array<string,mixed>>,total:?int,page:int,limit:int,pages:?int,next_cursor:?int}
     */
    public function search(array $input, string $role, string $view = ''): array
    {
        // Authorize the projection before any query, including the count query.
        $columns = self::projection($role, $view);
        $criteria = self::normalize($input);
        if ($view === 'id_cards') {
            $criteria['status'] = 'active';
            $criteria['eligible_for_id_card'] = true;
        } elseif ($view === 'archive') {
            $criteria['status'] = 'archived';
        } elseif ($view === 'new_members') {
            $criteria['membership_tier'] = 'temporary';
            if ($criteria['status'] === '') {
                $criteria['status'] = ''; // keep active/warning/inactive (default excludes archived)
            }
        }
        [$whereSql, $params] = self::whereClause($criteria, $this->supportsFullText);
        $total = null;
        $pages = null;
        $page = $criteria['page'];
        if ($criteria['include_total']) {
            $total = $this->count($whereSql, $params);
            $pages = max(1, (int)ceil($total / $criteria['limit']));
            $page = min($page, $pages);
        }
        $offset = ($page - 1) * $criteria['limit'];

        // New clients use an id cursor so deep pages do not make MariaDB walk
        // and discard an ever-growing OFFSET. Page/OFFSET remains a compatibility
        // adapter for older callers and direct links.
        $fetchWhereSql = $whereSql;
        $fetchParams = $params;
        if ($criteria['cursor'] > 0 && $criteria['sort'] === 'id' && $criteria['direction'] === 'desc') {
            $fetchWhereSql .= ' AND `id` < ?';
            $fetchParams[] = $criteria['cursor'];
            $offset = 0;
        }

        $columnSql = implode(', ', array_map(static function (string $column): string {
            return '`' . $column . '`';
        }, $columns));

        $sortSql = '`' . $criteria['sort'] . '` ' . strtoupper($criteria['direction']);
        if ($criteria['sort'] !== 'id') {
            $sortSql .= ', `id` DESC';
        }
        $sql = "SELECT {$columnSql}
                FROM `members`
                WHERE {$fetchWhereSql}
                ORDER BY {$sortSql}
                LIMIT ? OFFSET ?";
        $statement = $this->connection->prepare($sql);
        $fetchParams[] = $criteria['limit'] + 1;
        $fetchParams[] = $offset;
        self::execute($statement, $fetchParams);

        $members = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $members[] = $row;
        }
        $statement->closeCursor();

        $hasMore = count($members) > $criteria['limit'];
        if ($hasMore) {
            array_pop($members);
        }
        $nextCursor = null;
        if (
            $hasMore
            && $criteria['sort'] === 'id'
            && $criteria['direction'] === 'desc'
            && !empty($members)
        ) {
            $last = $members[count($members) - 1];
            $lastId = (int)($last['id'] ?? 0);
            $nextCursor = $lastId > 0 ? $lastId : null;
        }

        return [
            'members' => $members,
            'total' => $total,
            'page' => $page,
            'limit' => $criteria['limit'],
            'pages' => $pages,
            'next_cursor' => $nextCursor,
        ];
    }

    /** @return string[] */
    public function sections(): array
    {
        $sections = [];
        $statement = $this->connection->query(
            "SELECT DISTINCT `current_section`
             FROM `members`
             WHERE `status` IN ('active', 'warning', 'inactive')
               AND `current_section` IS NOT NULL
               AND `current_section` <> ''
             ORDER BY `current_section`
             LIMIT 200"
        );
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $sections[] = (string)$row['current_section'];
        }
        $statement->closeCursor();
        return $sections;
    }

    /** @return array{q:string,registration_type:string,member_type:string,status:string,gender:string,age_group:string,current_section:string,city:string,education_level:string,archive_type:string,page:int,limit:int,cursor:int,sort:string,direction:string,include_total:bool,eligible_for_id_card:bool} */
    private static function normalize(array $input): array
    {
        $text = static function ($value, int $max): string {
            $value = trim((string)$value);
            return function_exists('mb_substr')
                ? mb_substr($value, 0, $max, 'UTF-8')
                : substr($value, 0, $max);
        };
        $enum = static function ($value, array $allowed): string {
            $value = trim((string)$value);
            return in_array($value, $allowed, true) ? $value : '';
        };

        return [
            'q' => $text($input['q'] ?? '', 100),
            'registration_type' => $enum($input['registration_type'] ?? '', ['waiting', 'transfer', 'direct']),
            'member_type' => $enum($input['member_type'] ?? '', ['regular', 'special_regular', 'honorary']),
            'status' => $enum($input['status'] ?? '', ['active', 'warning', 'inactive', 'archived']),
            'gender' => $enum($input['gender'] ?? '', ['male', 'female']),
            'age_group' => $enum($input['age_group'] ?? '', ['7_13', '14_17', '18_plus']),
            'current_section' => $text($input['current_section'] ?? '', 100),
            'city' => $text($input['city'] ?? '', 100),
            // M2: the old 8-value set matched nothing the forms store ('high'
            // vs 'high_school') — unknown codes were silently unfiltered.
            // Canonical 12 codes, single-sourced from EducationLabels.
            'education_level' => $enum($input['education_level'] ?? '', EducationLabels::codes()),
            'archive_type' => $enum($input['archive_type'] ?? '', [
                'permanent_archive', 'failed_observation',
            ]),
            'membership_tier' => $enum($input['membership_tier'] ?? '', ['temporary', 'permanent']),
            'eligible_for_promotion' => (string)($input['eligible_for_promotion'] ?? '') === '1',
            'page' => max(1, (int)($input['page'] ?? 1)),
            'limit' => min(self::MAX_PAGE_SIZE, max(1, (int)($input['limit'] ?? self::DEFAULT_PAGE_SIZE))),
            'cursor' => max(0, (int)($input['cursor'] ?? 0)),
            'sort' => $enum($input['sort'] ?? 'id', [
                'id', 'member_code', 'student_name', 'father_name', 'gender',
                'age_group', 'current_section', 'status', 'registration_type',
                'phone_number', 'city', 'created_at',
            ]) ?: 'id',
            'direction' => $enum(strtolower((string)($input['direction'] ?? 'desc')), ['asc', 'desc']) ?: 'desc',
            'include_total' => (string)($input['include_total'] ?? '1') !== '0',
            // Internal-only capability set by search() for the id_cards view.
            'eligible_for_id_card' => false,
        ];
    }

    /** @return array{string,array<int,mixed>} */
    private static function whereClause(array $criteria, bool $supportsFullText): array
    {
        $where = [];
        $params = [];

        if ($criteria['status'] !== '') {
            $where[] = '`status` = ?';
            $params[] = $criteria['status'];
        } else {
            // Equivalent to status != 'archived' for the supported statuses,
            // while allowing the status index to satisfy normal list requests.
            $where[] = "`status` IN ('active', 'warning', 'inactive')";
        }

        if ($criteria['eligible_for_id_card']) {
            // H4 (updated 2026-11-18): promotions now flip waiting→direct,
            // but legacy permanent+waiting rows were never backfilled by
            // decision — the tier=permanent clause covers them. Keep in
            // sync with generate_id_card.php.
            $where[] = "(`registration_type` IN ('direct', 'transfer') OR `member_type` = 'honorary' OR `membership_tier` = 'permanent')";
        }

        if ($criteria['membership_tier'] !== '') {
            $where[] = '`membership_tier` = ?';
            $params[] = $criteria['membership_tier'];
        }

        if (!empty($criteria['eligible_for_promotion'])) {
            $where[] = '`membership_tier` = ? AND `registered_at` <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
            $params[] = 'temporary';
        }

        $filters = [
            'registration_type' => 'registration_type',
            'member_type' => 'member_type',
            'gender' => 'gender',
            'age_group' => 'age_group',
            'current_section' => 'current_section',
            'city' => 'city',
            'education_level' => 'education_level',
            'archive_type' => 'archive_type',
        ];
        foreach ($filters as $key => $column) {
            if ($criteria[$key] !== '') {
                $where[] = '`' . $column . '` = ?';
                $params[] = $criteria[$key];
            }
        }

        if ($criteria['q'] !== '') {
            $searchColumns = [
                'student_name', 'father_name', 'grandfather_name', 'member_code',
                'baptismal_name', 'phone_number', 'work_profession', 'city',
            ];
            if ($supportsFullText && self::textLength($criteria['q']) >= 3) {
                $columnSql = implode(', ', array_map(static function (string $column): string {
                    return '`' . $column . '`';
                }, $searchColumns));
                $ftWhere = "MATCH ({$columnSql}) AGAINST (? IN BOOLEAN MODE)";
                $ftParams = [self::booleanSearch($criteria['q'])];
                // M19: tolerate separators in phone/code queries. Boolean mode
                // splits '0911-23' into +0911* +23* (the second token kills the
                // match) and 'A 71045' never equals stored 'A-71045' — so also
                // try the digits-only phone prefix and the separator-stripped
                // code prefix. Gated at 3+ chars like the FULLTEXT branch.
                $digits = (string)preg_replace('/\D+/', '', $criteria['q']);
                $alnum = (string)preg_replace('/[^A-Za-z0-9]+/', '', $criteria['q']);
                $extraWhere = [];
                $extraParams = [];
                if (strlen($digits) >= 3) {
                    $extraWhere[] = "`phone_number` LIKE ? ESCAPE '='";
                    $extraParams[] = self::escapeLike($digits) . '%';
                }
                if (strlen($alnum) >= 3) {
                    $extraWhere[] = "REPLACE(`member_code`, '-', '') LIKE ? ESCAPE '='";
                    $extraParams[] = self::escapeLike($alnum) . '%';
                }
                if ($extraWhere !== []) {
                    $ftWhere = '(' . $ftWhere . ' OR ' . implode(' OR ', $extraWhere) . ')';
                    $ftParams = array_merge($ftParams, $extraParams);
                }
                $where[] = $ftWhere;
                foreach ($ftParams as $fp) {
                    $params[] = $fp;
                }
            } else {
                // Short terms use indexable prefixes. SQLite uses this branch in
                // integration tests because it has no MariaDB FULLTEXT syntax.
                $prefixColumns = ['student_name', 'father_name', 'member_code', 'phone_number'];
                $searchTerms = array_map(static function (string $column): string {
                    return '`' . $column . "` LIKE ? ESCAPE '='";
                }, $prefixColumns);
                $where[] = '(' . implode(' OR ', $searchTerms) . ')';
                $search = self::escapeLike($criteria['q']) . '%';
                foreach ($prefixColumns as $_column) {
                    $params[] = $search;
                }
            }
        }

        return [implode(' AND ', $where), $params];
    }

    private function count(string $whereSql, array $params): int
    {
        $statement = $this->connection->prepare("SELECT COUNT(*) AS `total` FROM `members` WHERE {$whereSql}");
        self::execute($statement, $params);
        $total = (int)$statement->fetchColumn();
        $statement->closeCursor();
        return $total;
    }

    private static function execute(PDOStatement $statement, array $params): void
    {
        foreach (array_values($params) as $index => $value) {
            $statement->bindValue(
                $index + 1,
                $value,
                is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
        $statement->execute();
    }

    /** @return string[] */
    private static function projection(string $role, string $view): array
    {
        if ($view === 'picker') {
            return self::PICKER_COLUMNS;
        }
        if ($view === 'id_cards') {
            if (!in_array($role, self::ID_CARD_ROLES, true)) {
                throw new InvalidArgumentException('The requested directory view is not allowed.');
            }
            return self::ID_CARD_COLUMNS;
        }
        if ($view === 'archive') {
            if (!in_array($role, self::PRIVILEGED_ROLES, true)) {
                throw new InvalidArgumentException('The requested directory view is not allowed.');
            }
            return self::ARCHIVE_COLUMNS;
        }
        if ($view === 'new_members') {
            if (!in_array($role, self::PRIVILEGED_ROLES, true)) {
                throw new InvalidArgumentException('The requested directory view is not allowed.');
            }
            return self::NEW_MEMBERS_COLUMNS;
        }
        if ($view === 'manager') {
            if (!in_array($role, self::PRIVILEGED_ROLES, true)) {
                throw new InvalidArgumentException('The requested directory view is not allowed.');
            }
            return self::MANAGER_COLUMNS;
        }
        if ($role === 'finance_dept') {
            return self::PICKER_COLUMNS;
        }
        if ($role === 'edu_dept') {
            return self::EDUCATION_COLUMNS;
        }
        if (in_array($role, self::PRIVILEGED_ROLES, true)) {
            return self::FULL_COLUMNS;
        }
        throw new InvalidArgumentException('The member directory is not available for this role.');
    }

    private static function booleanSearch(string $value): string
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_slice($tokens, 0, 8);
        $terms = [];
        foreach ($tokens as $token) {
            $token = function_exists('mb_substr')
                ? mb_substr($token, 0, 40, 'UTF-8')
                : substr($token, 0, 40);
            if ($token !== '') {
                $terms[] = '+' . $token . '*';
            }
        }
        // q is non-empty, but punctuation-only input can produce no tokens.
        return $terms !== [] ? implode(' ', $terms) : '"' . $value . '"';
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['=', '%', '_'], ['==', '=%', '=_'], $value);
    }
}
