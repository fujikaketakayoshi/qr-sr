<?php

declare(strict_types=1);

namespace QrRally\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use QrRally\Database\ConnectionFactory;
use QrRally\Database\Migrator;
use QrRally\Domain\ApplicationInput;
use QrRally\Repository\ApplicationRepository;

final class ApplicationRepositoryTest extends TestCase
{
    private string $directory;
    private PDO $database;
    private ApplicationRepository $applications;

    protected function setUp(): void
    {
        $this->directory=sys_get_temp_dir().'/qr-application-test-'.bin2hex(random_bytes(6)); mkdir($this->directory,0700,true);
        $this->database=(new ConnectionFactory())->connect($this->directory.'/test.sqlite');
        (new Migrator($this->database,dirname(__DIR__,2).'/database/migrations'))->migrate();
        $this->database->exec("INSERT INTO events(id,name,starts_at,ends_at,required_stamp_count,created_at,updated_at) VALUES(1,'event','2026-01-01','2026-12-31',1,'now','now')");
        $this->database->exec("INSERT INTO participants(token_hash,nickname,first_seen_at,last_seen_at,completed_at,created_at,updated_at) VALUES('hash','nick','now','now','now','now','now')");
        $this->applications=new ApplicationRepository($this->database);
    }

    protected function tearDown(): void { unset($this->database); foreach(glob($this->directory.'/*')?:[] as $file)@unlink($file); @rmdir($this->directory); }

    public function testSettingsAndApplicationUpdatePreserveNumberAndNullDisabledFields(): void
    {
        $settings=['name'=>['enabled'=>true,'required'=>true],'email'=>['enabled'=>false,'required'=>false],'address'=>['enabled'=>false,'required'=>false],'phone'=>['enabled'=>false,'required'=>false]];
        $this->applications->saveSettings(true,null,'抽選連絡のため',$settings);
        $first=$this->applications->save(1,new ApplicationInput(['name'=>'山田','email'=>'hidden@example.com','address'=>'','phone'=>''],true),$this->applications->fields());
        $second=$this->applications->save(1,new ApplicationInput(['name'=>'田中','email'=>'another@example.com','address'=>'','phone'=>''],true),$this->applications->fields());
        self::assertSame($first['application_number'],$second['application_number']);
        self::assertSame('田中',$second['name']);
        self::assertNull($second['email']);
        self::assertSame(1,(int)$this->applications->summary()['applications']);
    }

    public function testApplicationExportContainsOnlyApplicants(): void
    {
        self::assertSame([], $this->applications->exportApplicationRows());

        $this->database->exec("INSERT INTO participants(token_hash,nickname,first_seen_at,last_seen_at,created_at,updated_at) VALUES('other-hash','not-applied','now','now','now','now')");
        $this->applications->saveSettings(true, null, '抽選連絡のため', [
            'name' => ['enabled' => true, 'required' => true],
            'email' => ['enabled' => false, 'required' => false],
            'address' => ['enabled' => false, 'required' => false],
            'phone' => ['enabled' => false, 'required' => false],
        ]);
        $this->applications->save(1, new ApplicationInput(['name'=>'応募者','email'=>'','address'=>'','phone'=>''], true), $this->applications->fields());

        $rows = $this->applications->exportApplicationRows();
        self::assertCount(1, $rows);
        self::assertSame('nick', $rows[0]['nickname']);
        self::assertSame('応募者', $rows[0]['name']);
    }
}
