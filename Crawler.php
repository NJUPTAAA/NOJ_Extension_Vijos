<?php
namespace App\Babel\Extension\vijos;

use App\Babel\Crawl\CrawlerBase;
use App\Models\ProblemModel;
use App\Models\OJModel;
use KubAT\PhpSimple\HtmlDomParser;
use Requests;
use Exception;

class Crawler extends CrawlerBase
{
    public $oid = null;
    public $prefix = "Vijos";
    private $con;
    private $imgi;
    private $incremental;
    private $range;
    /**
     * Initial
     *
     * @return Response
     */
    public function start($conf)
    {
        $action = $conf["action"];
        $con = $conf["con"];
        $cached = $conf["cached"];
        $this->range = $conf["range"];
        $this->oid = OJModel::oid('vijos');

        if (is_null($this->oid)) {
            throw new Exception("Online Judge Not Found");
        }

        if ($action == 'judge_level') {
            $this->judge_level();
        } else {
            $incremental = $action == 'update_problem';
            $this->crawl($con);
        }
    }

    public function judge_level()
    {
        // TODO
    }

    public function crawl($con)
    {
        if ($con == 'all') {
            $page = 1;
            do {
                $this->_crawl($page++);
            } while ($page <= $this->lastPage);
        } else $this->__crawl($con);
    }

    private function _crawl($page = 1)
    {
        $res = Requests::get('https://vijos.org/p?page=' . $page);
        $count = preg_match_all('/href="\/p\/(\d+)"/', $res->body, $matches);
        for ($i = 0; $i < $count; ++$i) {
            if ($this->inRange($matches[1][$i], $this->range)!==false) {
                $this->__crawl($matches[1][$i]);
            }
        }
        if (preg_match('/page=(\d+)">末页/', $res->body, $match)) {
            $this->lastPage = $match[1];
        }
    }

    private function __crawl($con)
    {
        $this->con="VIJ$con";
        $this->imgi=1;
        $problemModel = new ProblemModel();

        if ($this->incremental && !empty($problemModel->basic($problemModel->pid('VIJ' . $con)))) {
            return;
        }

        $updmsg = $this->incremental ? 'Updating' : 'Crawling';
        $this->line("<fg=yellow>{$updmsg}:   </>VIJ$con");

        try {
            $dom = HtmlDomParser::file_get_html('https://vijos.org/p/' . $con, false, null, 0, -1, true, true, DEFAULT_TARGET_CHARSET, false);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), '404 Not Found') !== false) {
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Problem not found.</>\n");
            } else if (strpos($e->getMessage(), '403 Forbidden') !== false) {
                $this->line("\n  <bg=red;fg=white> Exception </> : <fg=yellow>Problem forbidden.</>\n");
            }
            return false;
        }

        $mainDiv = $dom->find(".section__body", 0);

        $eles = $mainDiv->children();
        array_push($eles, null);
        $this->pro['description'] = null;
        $this->pro['input'] = null;
        $this->pro['output'] = null;
        $this->pro['sample'] = [];
        $this->pro['note'] = null;
        $this->pro['sampleDesc'] = null;
        $this->pro['limit'] = null;
        $patterns = [
            'description' => '<h1>描述</h1>',
            '_format' => '<h1>格式</h1>',
            'input' => '<h2>输入格式</h2>',
            'output' => '<h2>输出格式</h2>',
            '_sample' => '/^<h1>样例\d+<\/h1>$/u',
            '__sampleInput' => '/^<h2>样例输入\d+<\/h2>$/u',
            '__sampleOutput' => '/^<h2>样例输出\d+<\/h2>$/u',
            'limit' => '<h1>限制</h1>',
            'note' => '<h1>提示</h1>',
            'sampleDesc' => '/<h1>样例(说明|解释)<\/h1>|<h2>样例说明1<\/h2>/', // P2036 has <h2>样例说明1</h2>
            'source' => '<h1>来源</h1>',
        ];
        $lastPart = '';
        $content = '';
        $cursample = [];
        foreach ($eles as $ele) {
            $html = $ele ? $ele->outertext : null;
            $match = !$ele;
            if (!$match) {
                foreach ($patterns as $key => $value) {
                    if ($value[0] != '/' && $html == $value || $value[0] == '/' && preg_match($value, $html)) {
                        $match = $key;
                        break;
                    }
                }
            }
            if (!$lastPart) {
                if ($match) {
                    $lastPart = $match;
                }
                continue;
            }
            if ($match) {
                if ($lastPart[0] != '_') {
                    $this->pro[$lastPart] = $content;
                    $content = '';
                } elseif ($lastPart == '__sampleOutput') { // Assume output always follows by input
                    array_push($this->pro['sample'], $cursample);
                    $cursample = [];
                }
                $lastPart = $match;
            } else {
                if ($lastPart[1] != '_') {
                    if ($lastPart != 'source') {
                        $content .= $html;
                    } else {
                        $content .= $ele->innertext;
                    }
                } else { // Code
                    $code = trim($ele->find('code', 0)->innertext);
                    if ($lastPart == '__sampleInput') {
                        if (isset($cursample['sampleInput'])) {
                            die($con);
                        }
                    } else {
                        if (isset($cursample['sampleOutput'])) {
                            die($con);
                        }
                    }
                    if (count($ele->children()) != 1) {
                        die($con);
                    }
                    if ($lastPart == '__sampleInput') {
                        $cursample['sample_input'] = $code;
                    } else {
                        $cursample['sample_output'] = $code;
                    }
                }
            }
            if (!$ele) {
                break;
            }
        }

        $this->pro['time_limit'] = 1000;
        $this->pro['memory_limit'] = 262144;
        if ($this->pro['sampleDesc']) {
            $this->pro['note'] = '<h3>样例说明</h3>' . $this->pro['sampleDesc'] . $this->pro['note'];
        }
        if ($this->pro['limit']) {
            $this->pro['note'] = $this->pro['limit'] . $this->pro['note'];
            $this->pro['time_limit'] = 0;
            $this->pro['memory_limit'] = 0;
        }

        $this->cacheImages(['description','input','output','note']); // caching images to locale storage

        $title = $dom->find('.section__header', 0)->find('h1', 0)->innertext;
        $this->pro['pcode'] = 'VIJ' . $con;
        $this->pro['OJ'] = $this->oid;
        $this->pro['contest_id'] = null;
        $this->pro['index_id'] = $con;
        $this->pro['origin'] = 'https://vijos.org/p/' . $con;
        $this->pro['title'] = $title;
        $this->pro['input_type'] = 'standard input';
        $this->pro['output_type'] = 'standard output';

        $this->pro['markdown'] = 0;
        $this->pro['tot_score'] = 100;
        $this->pro["partial"] = 1;
        $this->pro['source'] = "P{$con} {$title}";

        $info = $dom->find(".horizontal", 0);
        preg_match('/<dt>已通过<\/dt>[\s\S]*<dd>(\d+)<\/dd>/', $info->innertext, $match);
        $this->pro['solved_count'] = $match[1];

        $problem = $problemModel->pid($this->pro['pcode']);

        if ($problem) {
            $problemModel->clearTags($problem);
            $new_pid = $this->updateProblem($this->oid);
        } else {
            $new_pid = $this->insertProblem($this->oid);
        }

        $tags = $info->find('.hasjs--hide', 0);
        if ($tags) {
            foreach ($tags->find('a') as $tag) {
                $problemModel->addTags($new_pid, $tag->innertext);
            }
        }

        $donemsg = $this->incremental ? 'Updated' : 'Crawled';
        $this->line("<fg=green>$donemsg:    </>VIJ$con");
    }

    private function cacheImages($fields)
    {
        foreach($fields as $field) {
            if(filled($this->pro[$field])) {
                $this->pro[$field]=$this->cacheImage(HtmlDomParser::str_get_html($this->pro[$field], true, true, DEFAULT_TARGET_CHARSET, false));
                if($this->pro[$field]===false) {
                    $this->pro[$field]=null;
                }
            }
        }
    }

    private function cacheImage($dom)
    {
        if(!$dom) return $dom;
        foreach ($dom->find('img') as $ele) {
            $src = $ele->src;
            if (strpos($src, '://') !== false) {
                $url=$src;
            } elseif ($src[0]=='/') {
                $url='https://vijos.org'.$src;
            } else {
                $url='https://vijos.org/'.$src;
            }
            $res=Requests::get($url, ['Referer' => 'https://vijos.org']);
            $ext=['image/jpeg'=>'.jpg', 'image/png'=>'.png', 'image/gif'=>'.gif', 'image/bmp'=>'.bmp'];
            if (isset($res->headers['content-type'])) {
                $cext=$ext[$res->headers['content-type']];
            } else {
                $pos=strpos($ele->src, '.');
                if ($pos===false) {
                    $cext='';
                } else {
                    $cext=substr($ele->src, $pos);
                }
            }
            $fn=$this->con.'_'.($this->imgi++).$cext;
            $dir=base_path("public/external/vijos/img");
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(base_path("public/external/vijos/img/$fn"), $res->body);
            $ele->src='/external/vijos/img/'.$fn;
        }
        return $dom;
    }

    private function inRange($needle, $haystack)
    {
        $options=[];
        if(!is_null($haystack[0])) {
            $options['min_range']=$haystack[0];
        }
        if(!is_null($haystack[1])) {
            $options['max_range']=$haystack[1];
        }
        return filter_var($needle, FILTER_VALIDATE_INT, [
            'options' => $options
        ]);
    }
}
