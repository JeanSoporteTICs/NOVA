<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\ExternalClients\NextcloudOcsClient;
use App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NextcloudNativeClientTest extends TestCase
{
    public function test_webdav_paths_cannot_escape_the_personal_root(): void
    {
        $client = new NextcloudWebdavClient;

        self::assertSame('/Documentos/archivo.pdf', $client->pathSafe('../../Documentos/./archivo.pdf'));
        self::assertSame('/', $client->pathSafe('/../../'));
    }

    public function test_webdav_propfind_is_projected_to_native_file_rows(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:response><d:href>/remote.php/dav/files/jean/Documentos/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>
  <d:response><d:href>/remote.php/dav/files/jean/Documentos/Manual.pdf</d:href><d:propstat><d:prop><d:displayname>Manual.pdf</d:displayname><d:getcontenttype>application/pdf</d:getcontenttype><d:getcontentlength>123</d:getcontentlength><d:resourcetype/><oc:fileid>99</oc:fileid></d:prop></d:propstat></d:response>
</d:multistatus>
XML;
        $items = (new NextcloudWebdavClient)->propfindParse($xml);

        self::assertCount(1, $items);
        self::assertSame('Manual.pdf', $items[0]['name']);
        self::assertSame('/Documentos/Manual.pdf', $items[0]['path']);
        self::assertSame('file', $items[0]['type']);
        self::assertSame(123, $items[0]['size']);
    }

    public function test_ocs_sharing_client_uses_personal_basic_auth_and_native_contract(): void
    {
        Http::fake(['*' => Http::response(['ocs' => ['meta' => ['statuscode' => 100, 'message' => 'OK'], 'data' => [['id' => 7]]]], 200)]);

        $result = (new NextcloudOcsClient)->request(
            ['url' => 'https://cloud.example.test', 'admin_user' => 'personal-user', 'admin_pass' => 'secret'],
            'GET',
            '/shares?shared_with_me=true',
        );

        self::assertTrue($result['ok']);
        self::assertSame([['id' => 7]], $result['data']);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/ocs/v2.php/apps/files_sharing/api/v1/shares?shared_with_me=true&format=json')
            && $request->hasHeader('OCS-APIRequest', 'true')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('personal-user:secret')));
    }
}
