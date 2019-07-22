<?php
namespace App\Babel\Extension\vijos;

use App\Babel\Submit\Curl;
use App\Models\CompilerModel;
use App\Models\JudgerModel;
use App\Models\OJModel;
use Illuminate\Support\Facades\Validator;
use Requests;

class Submitter extends Curl
{
    protected $sub;
    public $post_data = [];
    protected $oid;
    protected $selectedJudger;

    public function __construct(&$sub, $all_data)
    {
        $this->sub = &$sub;
        $this->post_data = $all_data;
        $judger = new JudgerModel();
        $this->oid = OJModel::oid('vijos');
        if (is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }
        $judger_list = $judger->list($this->oid);
        $this->selectedJudger = $judger_list[array_rand($judger_list)];
    }

    private function _login()
    {
        $response = $this->grab_page([
            'site' => 'https://vijos.org',
            'oj' => 'vijos',
            'handle' => $this->selectedJudger["handle"],
        ]);
        if (strpos($response, '登出') === false) {
            $params = [
                'uname' => $this->selectedJudger["handle"],
                'password' => $this->selectedJudger["password"],
                'rememberme' => 'on',
            ];
            $this->login([
                'url' => 'https://vijos.org/login',
                'data' => http_build_query($params),
                'oj' => 'vijos',
                'handle' => $this->selectedJudger["handle"],
            ]);
        }
    }

    private function _submit()
    {
        $this->sub['jid'] = $this->selectedJudger["jid"];
        $pid = $this->post_data['iid'];
        $response = $this->grab_page([
            'site' => "https://vijos.org/p/{$pid}/submit",
            'oj' => 'vijos',
            'handle' => $this->selectedJudger["handle"],
        ]);
        preg_match('/"csrf_token":"([0-9a-f]{64})"/', $response, $match);

        $params = [
            'lang' => $this->post_data['lang'],
            'code' => $this->post_data["solution"],
            'csrf_token' => $match[1],
        ];
        $response = $this->post_data([
            'site' => "https://vijos.org/p/{$pid}/submit",
            'data' => http_build_query($params),
            'oj' => "vijos",
            'ret' => true,
            'returnHeader' => true,
            'handle' => $this->selectedJudger["handle"],
        ]);
        if (preg_match('/\nLocation: \/records\/(.+)/i', $response, $match)) {
            $this->sub['remote_id'] = $match[1];
        } else {
            $this->sub['verdict'] = 'Submission Error';
        }
    }

    public function submit()
    {
        $validator = Validator::make($this->post_data, [
            'pid' => 'required|integer',
            'coid' => 'required|integer',
            'iid' => 'required|integer',
            'solution' => 'required',
        ]);

        if ($validator->fails()) {
            $this->sub['verdict'] = "System Error";
            return;
        }

        $this->_login();
        $this->_submit();
    }
}
