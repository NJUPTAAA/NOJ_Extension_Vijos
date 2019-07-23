<?php
namespace App\Babel\Extension\vijos;

use App\Babel\Submit\Curl;
use App\Models\SubmissionModel;
use App\Models\ContestModel;
use App\Models\JudgerModel;
use Requests;
use Exception;
use Log;

class Judger extends Curl
{

    public $verdict=[
        'Accepted'=>"Accepted",
        'Wrong Answer'=>"Wrong Answer",
        'Time Exceeded'=>"Time Limit Exceed",
        "Memory Exceeded"=>"Memory Limit Exceed",
        'Runtime Error'=>"Runtime Error",
        'Compile Error'=>"Compile Error",
        'System Error'=>"Submission Error",
        'Canceled'=>"Submission Error",
        'Unknown Error'=>"Submission Error",
        'Ignored'=>"Submission Error",
    ];


    public function __construct()
    {
        $this->contestModel=new ContestModel();
        $this->submissionModel=new SubmissionModel();
    }

    public function judge($row)
    {
        try {
            $sub=[];
            $res=Requests::get('https://vijos.org/records/'.$row['remote_id']);
            preg_match('/<span class="record-status--text \w*">\s*(.*?)\s*<\/span>/', $res->body, $match);
            $status=$match[1];
            if (!array_key_exists($status, $this->verdict)) {
                return;
            }
            if ($match[1]=='Compile Error') {
                preg_match('/<pre class="compiler-text">([\s\S]*?)<\/pre>/', $res->body, $match);
                $sub['compile_info']=html_entity_decode($match[1], ENT_QUOTES);
            }
            $sub['verdict']=$this->verdict[$status];
            preg_match('/<dt>分数<\/dt>\s*<dd>(\d+)<\/dd>/', $res->body, $match);
            $isOI=$row['cid'] && $this->contestModel->rule($row['cid'])==2;
            if ($isOI) {
                $sub['score']=$match[1];
                if ($sub['verdict']=="Wrong Answer" && $sub['score']!=0) {
                    $sub['verdict']='Partially Accepted';
                }
            } else {
                $sub['score']=$match[1]==100 ? 100 : 0;
            }
            $sub['remote_id']=$row['remote_id'];
            if ($sub['verdict']!="Submission Error" && $sub['verdict']!="Compile Error") {
                $maxtime=0;
                preg_match_all('/<td class="col--time">(?:&ge;)?(\d+)ms<\/td>/', $res->body, $matches);
                foreach ($matches[1] as $match) {
                    if ($match>$maxtime) {
                        $maxtime=$match;
                    }
                }
                $sub['time']=$maxtime;
                preg_match('/<dt>峰值内存<\/dt>\s*<dd>(?:&ge;)?([\d.]+) ([KM])iB<\/dd>/', $res->body, $match);
                $memory=$match[1];
                if ($match[2]=='M') {
                    $memory*=1024;
                }
                $sub['memory']=intval($memory);
            } else {
                $sub['memory']=0;
                $sub['time']=0;
            }

            // $ret[$row['sid']]=[
            //     "verdict"=>$sub['verdict']
            // ];
            $this->submissionModel->updateSubmission($row['sid'], $sub);
        } catch (Exception $e) {
        }
    }
}
