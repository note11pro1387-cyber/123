<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

final class W5P1FakePDO extends PDO
{
    public bool $transaction = false;
    public bool $activeUser = true;
    public int $userId = 7;
    public int $nextProjectId = 100;
    public int|string $lastId = 0;
    public array $projects = [];
    public array $milestones = [];
    public array $events = [];
    public array $audits = [];
    public array $outbox = [];
    public array $idempotency = [];
    public ?string $failAt = null;
    public bool $failCommit = false;
    private ?string $snapshot = null;

    public function __construct() {}
    public function beginTransaction(): bool
    {
        if ($this->transaction) return false;
        $this->snapshot = serialize([$this->projects,$this->milestones,$this->events,$this->audits,$this->outbox,$this->idempotency,$this->nextProjectId,$this->lastId]);
        $this->transaction = true;
        return true;
    }
    public function commit(): bool { if (!$this->transaction || $this->failCommit) return false; $this->transaction=false; $this->snapshot=null; return true; }
    public function rollBack(): bool
    {
        if (!$this->transaction) return false;
        [$this->projects,$this->milestones,$this->events,$this->audits,$this->outbox,$this->idempotency,$this->nextProjectId,$this->lastId] = unserialize((string)$this->snapshot, ['allowed_classes'=>false]);
        $this->transaction=false; $this->snapshot=null; return true;
    }
    public function inTransaction(): bool { return $this->transaction; }
    public function lastInsertId(?string $name = null): string|false { return (string)$this->lastId; }
    public function prepare(string $query, array $options = []): PDOStatement|false { return new W5P1FakePDOStatement($this, $query); }

    public function run(string $sql, array $p): array
    {
        if (str_starts_with($sql, "SELECT id FROM users WHERE id = ? AND account_status = 'active'")) {
            return ['rows' => $this->activeUser && (int)($p[0] ?? 0) === $this->userId ? [['id'=>$this->userId]] : []];
        }
        if (str_starts_with($sql, 'SELECT id, request_hash, state, response_status, response_json, created_at, expires_at, completed_at FROM idempotency_keys')) {
            $key = self::idemKey($p[0], $p[1], $p[2], $p[3]);
            return ['rows' => isset($this->idempotency[$key]) ? [$this->idempotency[$key]] : []];
        }
        if (str_starts_with($sql, 'INSERT INTO idempotency_keys')) {
            $key = self::idemKey($p[0], $p[1], $p[2], $p[3]);
            if (isset($this->idempotency[$key])) throw new PDOException('duplicate key', 23000);
            $this->idempotency[$key] = ['id'=>count($this->idempotency)+1,'request_hash'=>$p[4],'state'=>'processing','response_status'=>null,'response_json'=>null,'created_at'=>'2026-10-13 00:00:00','expires_at'=>$p[5],'completed_at'=>null];
            return ['rows'=>[], 'affected'=>1];
        }
        if (str_starts_with($sql, "UPDATE idempotency_keys SET state = 'completed'")) {
            if ($this->failAt === 'idempotency_complete') throw new RuntimeException('Injected test failure at idempotency_complete');
            [$status,$json,$scope,$scopeId,$route,$hash,$requestHash] = $p;
            $key = self::idemKey($scope,$scopeId,$route,$hash);
            $row = $this->idempotency[$key] ?? null;
            if (is_array($row) && $row['state']==='processing' && hash_equals((string)$row['request_hash'], (string)$requestHash)) {
                $this->idempotency[$key]['state']='completed'; $this->idempotency[$key]['response_status']=$status; $this->idempotency[$key]['response_json']=$json; $this->idempotency[$key]['completed_at']='2026-10-13 00:00:00';
                return ['rows'=>[], 'affected'=>1];
            }
            return ['rows'=>[], 'affected'=>0];
        }
        if (str_starts_with($sql, "SELECT COUNT(*) FROM projects WHERE owner_user_id = ? AND archived_at IS NULL")) {
            return ['scalar'=>count(array_filter($this->projects, static fn($r)=>$r['archived_at']===null && !in_array($r['status'], ['completed','rejected','cancelled'], true)))];
        }
        if (str_starts_with($sql, 'INSERT INTO projects (')) {
            [$public,$tracking,$owner,$title,$type,$brief] = $p;
            $id = $this->nextProjectId++;
            $this->lastId = $id;
            $this->projects[$id] = ['id'=>$id,'public_id'=>$public,'tracking_code'=>$tracking,'owner_user_id'=>$owner,'title'=>$title,'project_type'=>$type,'status'=>'submitted','progress'=>0,'brief_json'=>$brief,'created_at'=>'2026-10-13 15:30:00','archived_at'=>null];
            return ['rows'=>[], 'affected'=>1];
        }
        if (str_starts_with($sql, 'SELECT created_at FROM projects WHERE id = ?')) {
            $row = $this->projects[(int)$p[0]] ?? null;
            return ['scalar'=>$row['created_at'] ?? false];
        }
        foreach ([
            'INSERT INTO project_milestones' => 'milestones',
            'INSERT INTO project_events' => 'events',
            'INSERT INTO audit_logs' => 'audits',
            'INSERT INTO outbox_events' => 'outbox',
        ] as $prefix=>$property) {
            if (str_starts_with($sql, $prefix)) {
                if ($this->failAt === $property) throw new RuntimeException('Injected test failure at '.$property);
                $this->{$property}[] = ['params'=>$p];
                $this->lastId = count($this->{$property});
                return ['rows'=>[], 'affected'=>1];
            }
        }
        throw new RuntimeException('Unimplemented fake SQL: '.substr($sql,0,100));
    }
    private static function idemKey(mixed $scope, mixed $scopeId, mixed $route, mixed $hash): string { return implode('|', [(string)$scope,(string)$scopeId,(string)$route,(string)$hash]); }
}

final class W5P1FakePDOStatement extends PDOStatement
{
    private array $result = ['rows'=>[]];
    private int $index = 0;
    public function __construct(private W5P1FakePDO $db, private string $sql) {}
    public function execute(?array $params = null): bool { $this->result=$this->db->run($this->sql, $params ?? []); $this->index=0; return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->result['rows'][$this->index++] ?? false; }
    public function fetchColumn(int $column = 0): mixed { if (array_key_exists('scalar',$this->result)) return $this->result['scalar']; $row=$this->result['rows'][0] ?? null; if (!is_array($row)) return false; return array_values($row)[$column] ?? false; }
    public function rowCount(): int { return (int)($this->result['affected'] ?? 0); }
}

$checks = 0;
function w5check(bool $condition, string $label): void { global $checks; $checks++; if (!$condition) throw new RuntimeException('FAIL: '.$label); echo "PASS: {$label}\n"; }
function validW5Payload(): array
{
    return ['industry'=>'tech','purpose'=>'saas','colors'=>['palettes'=>['neon'],'customColors'=>['#123ABC','#ABCDEF']],'style'=>'modern','features'=>['ai','seo'],'content'=>['logo'=>'ready','text'=>'partial','images'=>'ready'],'timeline'=>'normal','fullName'=>'کاربر آزمایشی','phone'=>'۰۹۱۲۳۴۵۶۷۸۹','email'=>'test@example.test','messenger'=>'تلگرام','messengerId'=>'test-user','userNote'=>'شرح پروژه آزمایشی'];
}
function expectW5Problem(callable $action, int $status, string $code, string $label): void
{
    try { $action(); } catch (ApiProblemException $e) { w5check($e->httpStatus===$status && $e->errorCode===$code, $label); return; }
    throw new RuntimeException('Expected API problem: '.$label);
}

$dto = ProjectCreationDTO::fromArray(validW5Payload());
w5check($dto->projectType==='tech_saas' && $dto->title==='فناوری — فروش SaaS', 'DTO derives project title/type from permitted enums');
w5check($dto->values['phone']==='+989123456789', 'DTO normalizes Persian mobile digits to canonical Iran format');
w5check(count($dto->brief()['features'])===2 && $dto->brief()['content']['text']==='partial', 'DTO preserves meaningful NEO fields');
$mutated=validW5Payload(); $mutated['user_id']=7;
expectW5Problem(fn()=>ProjectCreationDTO::fromArray($mutated),422,'SERVER_OWNED_PROJECT_FIELD','reject server-owned user_id');
$mutated=validW5Payload(); $mutated['progress']=100;
expectW5Problem(fn()=>ProjectCreationDTO::fromArray($mutated),422,'SERVER_OWNED_PROJECT_FIELD','reject server-owned progress');
$mutated=validW5Payload(); $mutated['unreviewedField']='x';
expectW5Problem(fn()=>ProjectCreationDTO::fromArray($mutated),422,'UNKNOWN_PROJECT_FIELD','reject unknown top-level field');
$mutated=validW5Payload(); $mutated['purpose']='admin';
expectW5Problem(fn()=>ProjectCreationDTO::fromArray($mutated),422,'INVALID_PROJECT_FIELD','reject invalid cross-field purpose');
$mutated=validW5Payload(); $mutated['colors']['customColors']=['<img src=x onerror=alert(1)>'];
expectW5Problem(fn()=>ProjectCreationDTO::fromArray($mutated),422,'INVALID_PROJECT_COLOR','reject unsafe custom color markup');
$mutated=validW5Payload(); $mutated['colors']=['palettes'=>[],'customColors'=>[]];
expectW5Problem(fn()=>ProjectCreationDTO::fromArray($mutated),422,'PROJECT_COLORS_REQUIRED','require a palette or custom color');

$pdo = new W5P1FakePDO();
$service = new ProjectCreationService($pdo);
$key = 'test-key-'.str_repeat('a',20);
$first = $service->create(7, str_repeat('a',32), $dto, $key, 'req-test-1', '127.0.0.1', 'W5P1-Test');
w5check($first['status']===201 && $first['data']['trackingCode']===$pdo->projects[100]['tracking_code'], 'canonical create returns server-generated receipt only');
w5check(count($pdo->projects)===1 && count($pdo->milestones)===6, 'project and six default milestones persisted within orchestration');
w5check(count($pdo->events)===1 && count($pdo->audits)===1 && count($pdo->outbox)===1, 'initial event, audit and queue-only outbox written');
w5check(count($pdo->idempotency)===1 && array_values($pdo->idempotency)[0]['state']==='completed' && !$pdo->inTransaction(), 'idempotency completed before commit and transaction closed');
$replay = $service->create(7, str_repeat('a',32), $dto, $key, 'req-test-2');
w5check($replay['replayed']===true && $replay['data']===$first['data'] && count($pdo->projects)===1, 'same key + same normalized payload replays original result without duplicate');
$changed = validW5Payload(); $changed['userNote']='different request'; $changedDto=ProjectCreationDTO::fromArray($changed);
expectW5Problem(fn()=>$service->create(7,str_repeat('a',32),$changedDto,$key,'req-test-3'),409,'IDEMPOTENCY_KEY_REUSED','same key + changed payload conflicts safely');

$full = new W5P1FakePDO();
for ($i=0;$i<3;$i++) $full->projects[10+$i]=['id'=>10+$i,'status'=>'submitted','archived_at'=>null];
$fullService=new ProjectCreationService($full);
expectW5Problem(fn()=>$fullService->create(7,str_repeat('a',32),$dto,'at-capacity-key','req-limit'),429,'PROJECT_LIMIT_REACHED','server-enforced active project cap rejects fourth project');
w5check(count($full->projects)===3 && $full->idempotency===[] && !$full->inTransaction(), 'capacity rejection rolls back idempotency reservation');

$rollback = new W5P1FakePDO(); $rollback->failAt='outbox';
try { (new ProjectCreationService($rollback))->create(7,str_repeat('a',32),$dto,'rollback-key','req-rollback'); throw new RuntimeException('Expected injected outbox failure'); }
catch (RuntimeException $e) { w5check(str_contains($e->getMessage(),'Injected test failure'), 'injected downstream failure is observed by the test'); }
w5check($rollback->projects===[] && $rollback->milestones===[] && $rollback->events===[] && $rollback->audits===[] && $rollback->outbox===[] && $rollback->idempotency===[] && !$rollback->inTransaction(), 'failure rolls back project, children, audit/outbox and idempotency together');

$lateRollback = new W5P1FakePDO(); $lateRollback->failAt='idempotency_complete';
try { (new ProjectCreationService($lateRollback))->create(7,str_repeat('a',32),$dto,'late-rollback-key','req-late-rollback'); throw new RuntimeException('Expected injected completion failure'); }
catch (RuntimeException $e) { w5check(str_contains($e->getMessage(),'Injected test failure at idempotency_complete'), 'idempotency completion failure is observed after downstream writes'); }
w5check($lateRollback->projects===[] && $lateRollback->milestones===[] && $lateRollback->events===[] && $lateRollback->audits===[] && $lateRollback->outbox===[] && $lateRollback->idempotency===[] && !$lateRollback->inTransaction(), 'late idempotency failure rolls back all domain and queue records');

$commitFailure = new W5P1FakePDO(); $commitFailure->failCommit = true;
try { (new ProjectCreationService($commitFailure))->create(7,str_repeat('a',32),$dto,'commit-failure-key','req-commit-failure'); throw new RuntimeException('Expected injected commit failure'); }
catch (RuntimeException $e) { w5check(str_contains($e->getMessage(),'transaction could not be committed'), 'failed PDO commit is not reported as project success'); }
w5check($commitFailure->projects===[] && $commitFailure->milestones===[] && $commitFailure->events===[] && $commitFailure->audits===[] && $commitFailure->outbox===[] && $commitFailure->idempotency===[] && !$commitFailure->inTransaction(), 'failed commit triggers transaction rollback without returning receipt');

$inactive = new W5P1FakePDO(); $inactive->activeUser=false;
expectW5Problem(fn()=>(new ProjectCreationService($inactive))->create(7,str_repeat('a',32),$dto,'inactive-key','req-inactive'),401,'AUTH_REQUIRED','inactive user cannot create project');
w5check($inactive->projects===[] && $inactive->idempotency===[] && !$inactive->inTransaction(), 'inactive user failure creates no records');
try { (new UserRepository(new W5P1FakePDO()))->lockActiveForProjectCreation(7); throw new RuntimeException('Expected transaction guard'); }
catch (LogicException $e) { w5check(true, 'capacity lock refuses use outside transaction'); }

echo "W5-P1 MOCK TESTS: {$checks} assertions passed; MOCK_ONLY, not real DB integration.\n";
