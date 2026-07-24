<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('redmine_mantencion_eventos', function (Blueprint $table): void {
            $table->id(); $table->string('canal', 30)->index(); $table->string('tipo', 80)->nullable()->index();
            $table->string('mensaje_id', 160)->nullable()->index(); $table->text('detalle')->nullable();
            $table->json('contexto')->nullable(); $table->timestamp('registrado_at')->useCurrent()->index();
        });
        if (!Schema::hasTable('redmine_mantencion_storage')) return;
        $security = (string)(DB::table('redmine_mantencion_storage')->where('path', 'security.log')->value('payload_text') ?? '');
        foreach (preg_split('/\R+/', trim($security)) ?: [] as $line) {
            if (!preg_match('/^\[([^]]+)]\s+([A-Z_]+)\s+-\s+(.*)$/', trim($line), $m)) continue;
            $date = DateTimeImmutable::createFromFormat('d-m-Y H:i:s', $m[1], new DateTimeZone('America/Santiago'));
            DB::table('redmine_mantencion_eventos')->insert(['canal'=>'seguridad','tipo'=>$m[2],'detalle'=>$m[3],'registrado_at'=>$date?$date->format('Y-m-d H:i:s'):now()]);
        }
        $raw = (string)(DB::table('redmine_mantencion_storage')->where('path', 'envio_errores.log')->value('payload_text') ?? '');
        $depth=0; $buffer=''; $inString=false; $escape=false;
        for ($i=0,$len=strlen($raw);$i<$len;$i++) { $char=$raw[$i]; if($depth===0&&trim($char)==='')continue; $buffer.=$char;
            if($escape){$escape=false;continue;} if($char==='\\'){$escape=true;continue;} if($char==='"'){$inString=!$inString;continue;} if($inString)continue;
            if($char==='{')$depth++; if($char!=='}')continue; $depth--; if($depth!==0)continue; $payload=json_decode($buffer,true);
            if(is_array($payload)) DB::table('redmine_mantencion_eventos')->insert(['canal'=>'redmine','tipo'=>(string)($payload['event']??$payload['status']??'envio'),'mensaje_id'=>trim((string)($payload['message_id']??''))?:null,'detalle'=>(string)($payload['error']??$payload['message']??''),'contexto'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'registrado_at'=>now()]); $buffer='';
        }
        Schema::drop('redmine_mantencion_storage');
    }
    public function down(): void {}
};
