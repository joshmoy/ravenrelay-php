<?php
declare(strict_types=1);
require getenv('RAVENRELAY_PHP_AUTOLOAD') ?: __DIR__.'/../src/RavenRelay.php';
use RavenRelay\{RavenRelay, ApiException, ProtocolException, TransportException, Webhooks};
function check(bool $condition, string $message = 'assertion'): void { if (!$condition) throw new RuntimeException($message); }
function fails(callable $action, string $class): Throwable {
    try { $action(); } catch (Throwable $error) { check($error instanceof $class, get_class($error)); return $error; }
    throw new RuntimeException('Expected '.$class);
}
$base = $argv[1];
function client(string $case, float $timeout = 2): RavenRelay { global $base; return new RavenRelay('rr_test_contract', $base.'/'.$case.'/', $timeout); }
$body = ['from'=>'sender@example.com','to'=>['a@example.com','b@example.com'],'subject'=>'Hello ☃',
    'text'=>"line one\nline two",'metadata'=>['number'=>3,'enabled'=>true],'tags'=>['kind'=>'welcome']];
check(client('send')->emails->send($body, 'retry-123')['id'] === 'email_123');
check(client('template')->emails->send(['from'=>'sender@example.com','to'=>'a@example.com','template'=>'welcome','variables'=>['name'=>'Zoë','count'=>2,'active'=>true]])['status'] === 'queued');
check(client('suppressed')->emails->send($body)['status'] === 'suppressed');
check(client('simulated')->emails->send($body)['test_mode'] === true);
check(client('get')->emails->get('email/id +?')['to'] === ['a@example.com']);
check(client('list')->emails->list('queued','a+tag@example.com','cur /?+')['next_cursor'] === null);
check(client('empty-list')->emails->list()['emails'] === []);
foreach ([['unauthorized',401,'invalid_api_key'],['validation',422,'validation_error'],['rate',429,'rate_limit_exceeded'],['server-error',500,'internal_error'],['unknown-error',503,'unknown_error'],['bad-error',502,'unknown_error'],['non-json-error',502,'unknown_error'],['redirect',307,'unknown_error'],['bad-fields',503,'unknown_error']] as [$case,$status,$code]) {
    $error = fails(fn()=>client($case)->emails->send($body), ApiException::class);
    check($error->status === $status && $error->errorCode === $code, $case);
}
foreach (['malformed','array','null','oversized','deep-json','invalid-utf8'] as $case) fails(fn()=>client($case)->emails->send($body), ProtocolException::class);
foreach (['timeout','slow-body','truncated','disconnect','drip-body'] as $case) fails(fn()=>client($case, .1)->emails->send($body), TransportException::class);
foreach (['',"key\r\ninjected"] as $key) fails(fn()=>new RavenRelay($key), InvalidArgumentException::class);
foreach (['file:///etc/passwd','https://user:pass@example.com','https://example.com?key=secret'] as $url) fails(fn()=>new RavenRelay('key',$url), InvalidArgumentException::class);
foreach ([0.0, INF, NAN] as $timeout) fails(fn()=>new RavenRelay('key','https://example.com',$timeout), InvalidArgumentException::class);
fails(fn()=>client('invalid')->emails->send($body, "x\nInjected: yes"), InvalidArgumentException::class);
foreach (json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR) as $v) {
    check(Webhooks::verify($v['secret'],$v['timestamp'],$v['signature'],$v['body'],(float)$v['now']) === $v['valid'], $v['name']);
}
check(!Webhooks::verify('x','1','v1='.str_repeat('a',64),'',NAN));
echo "PHP: API, validation, timeout and 18 webhook checks passed.\n";

foreach (['http://example.com','http://127.0.0.1.evil.test','http://2130706433','http://127.1','http://localhost.evil.test',"https://example.com\\@evil.test","https://example.com\n"] as $url) fails(fn()=>new RavenRelay('key',$url), InvalidArgumentException::class);
foreach (['.','..'] as $id) fails(fn()=>client('invalid')->emails->get($id), InvalidArgumentException::class);

fails(fn()=>(new RavenRelay('rr_test_contract',getenv('RAVENRELAY_TLS_TEST_URL')))->emails->send($body), TransportException::class);
