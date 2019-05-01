<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Log;
use \demi\api\Client;
use Carbon\Carbon;

/**
 * Class deletePostsCommand
 *
 * @category Console_Command
 * @package  App\Console\Commands
 */


class SyncFingerprint extends Command
{
    protected $baseApi = "http://localhost:8000/";
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = "fingerprint:sync {--dump}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Sync Fingerprint Data | {--dump}";


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $fingerAddr = env("FINGERPRINT_IP", "127.0.0.1");
        $hostApi = env("CLOUD_HOST", "http://localhost");

        // $hostApi = $this->option('host-api') . "/";
        // $fingerAddr = $this->option('finger-addr');
        $isDumped = $this->option('dump');

        $this->info($this->description);
        $this->info("Syncing Data...");

        $this->info("API Address: " . $hostApi);
        $this->info("Fingerprint Address: " .$fingerAddr);

        $machine = $this->getDataFromMachine($fingerAddr);
        if($isDumped) {
            dd($machine);
        }
        $fetchedData = $machine["fetched_data"];
        $isEmptyFetchedData = collect($fetchedData)->isEmpty();
        if($machine["is_connected"] && (!$isEmptyFetchedData)) {
            $data = $this->syncData($hostApi, $fetchedData);
        } else {
            $msg = "Not Connected or Empty data from machine. " . $fingerAddr;
            $this->info($msg);
            Log::error($msg);
            Log::error(print_r($machine, true));
        }
    }

    private function syncData($hostApi, $data) {
        $client = new \demi\api\Client([
            'baseUri' => $hostApi,
            'timeout' => 30,
            'defaultHeaders' => [],
            'defaultQueryParams' => [],
        ]);

        // @TODO(albert): di data ini filter hanya hari ini saja.
        // $data = [
        //     [
        //         "ts" => "2019-04-02 09:11:10",
        //         "finger_id" => 2
        //     ],
        //     [
        //         "ts" => "2019-04-02 10:11:10",
        //         "finger_id" => 5
        //     ],
        //     [
        //         "ts" => "2019-04-02 13:11:10",
        //         "finger_id" => 2
        //     ],
        // ];

        // Filter: Only send today's data
        $onlyTodayData = collect($data)->filter(function($dt) {
            $now = Carbon::now()->format('Y-m-d');
            $tsCarbon = Carbon::parse($dt["ts"])->format('Y-m-d');
            return $now == $tsCarbon;
        });

        $request = $client->post('api/fingerprint/sync')
        ->setPostParam('data', $onlyTodayData->toArray()) // Single param
        ->setHeaderParam('Connection', 'Keep-Alive') // Header value
        ->setHeaderParam(['Accept' => 'application/json', 'Some-Custom' => 'value']); // Headers array

        $response = $request->send();

        $statusCode = $response->statusCode(); // Response code: 200, 201, 204, etc...
        $bodyText = $response->body(); // Content
        $bodyJson = $response->json(); // Json decoded content
        $headerParams = $response->headers(); // Headers array
        // $headerValue = $response->headerValue('Encoding', 'Default value'); // Some header value


        if($statusCode == 200) {
            dump($bodyJson);
            Log::info("Success Sync.");
            Log::info(print_r($bodyJson, true));
            $this->info("DONE!");
        } else {
            Log::error("Failed to Sync. Code: " . $statusCode);
            Log::error($bodyText);
            dump($response);
            $this->info("PROBLEM FOUND! " . $statusCode);
        }
        // return [
        //     "code" => $statusCode,
        //     "data" => $bodyText
        // ];
    }

    private function getDataFromMachine($IP, $machineKey="0") {
        $ret = [
            "is_connected" => false,
            "fetched_data" => []
        ];

        $connect = fsockopen($IP, "80", $errno, $errstr, 1);
        if($connect){
            $soapRequest="<GetAttLog><ArgComKey xsi:type=\"xsd:integer\">".$machineKey."</ArgComKey><Arg><PIN xsi:type=\"xsd:integer\">All</PIN></Arg></GetAttLog>";
            $newLine="\r\n";
            fputs($connect, "POST /iWsService HTTP/1.0".$newLine);
            fputs($connect, "Content-Type: text/xml".$newLine);
            fputs($connect, "Content-Length: ".strlen($soapRequest).$newLine.$newLine);
            fputs($connect, $soapRequest.$newLine);
            $buffer="";
            while($responseData=fgets($connect, 1024)){
                $buffer=$buffer.$responseData;
            }

            $ret["is_connected"] = true;
            // Log::info('Connected to Machine. IP: ' . $IP);
        } else {
            $ret["is_connected"] = false;
            Log::error('Failed to connect to machine. IP: ' . $IP);
        }

        $buffer= $this->parseMachineData($buffer,"<GetAttLogResponse>","</GetAttLogResponse>");
        $buffer=explode("\r\n",$buffer);
        // echo "<pre>";
        // var_dump($buffer);
        // echo "</pre>";
        $hasil = [];
        for($a=0;$a<count($buffer);$a++){
            $data= $this->parseMachineData($buffer[$a],"<Row>","</Row>");
            $PIN= $this->parseMachineData($data,"<PIN>","</PIN>");
            $DateTime= $this->parseMachineData($data,"<DateTime>","</DateTime>");
            $Verified= $this->parseMachineData($data,"<Verified>","</Verified>");
            $Status= $this->parseMachineData($data,"<Status>","</Status>");

            $arr = [
                "finger_id" => $PIN,
                "ts" => $DateTime,
                "verified" => $Verified,
                "status" => $Status
            ];
            if(! empty($PIN)) {
                array_push($hasil, $arr);
            }
        }

        $ret["fetched_data"] = $hasil;
        return $ret;
    }

    private function parseMachineData($data,$p1,$p2){
        $data=" ".$data;
        $hasil="";
        $awal=strpos($data,$p1);
        if($awal!=""){
            $akhir=strpos(strstr($data,$p1),$p2);
            if($akhir!=""){
                $hasil=substr($data,$awal+strlen($p1),$akhir-strlen($p1));
            }
        }
        return $hasil;
    }
}