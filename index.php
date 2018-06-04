<?php
/**
 * BCPHP
 * 
 * PHP によるブロックチェーン
 * 
 * @category Brockchain
 * @package  Bcphp
 * @author   sola <sola.io.er@gmail.com>
 * @license  The MIT license https://opensource.org/licenses/mit-license.php
 * @version  1.1
 * @link     https://qiita.com/hidehiro98/items/841ece65d896aeaa8a2a
 * @link     http://co.bsnws.net/article/107
 * @link     https://qiita.com/iritec/items/5342a8b6031c982c85c4
 **/

define('NODE_URL', 'http://localhost/bcphp/');
define('NEIGHBOUR_NODE_URL', '');

//ini_set('display_errors', 1);

use Ramsey\Uuid\Uuid;

require_once 'vendor/autoload.php';

ORM::configure('mysql:host=localhost;dbname=bcphp');
ORM::configure('username', 'root');
ORM::configure('password', 'root');

$bcphp = new Bcphp;
$bcphp->api();

/**
 * メインクラス
 */
class Bcphp
{
    /**
     * 初期化
     */
    public function __construct()
    {
        $this->_initDb();
        $this->_initBc();
    }

    /**
     * API 用
     */
    public function api()
    {
        $mode = '';
        if (isset($_GET['mode'])) {
            $mode = $_GET['mode'];
        }
        $result = [
            'error' => 'Please specify the mode.'
        ];
        if ($mode === '') {
            echo json_encode($result);
            exit;
        }
        $json = file_get_contents('php://input');
        $array = json_decode($json, true);
        if ($array === false) {
            $result = [
                'error' => 'Please post JSON.'
            ];
            echo json_encode($result);
            exit;
        }
        $result = [
            'error' => 'Please specify the correct mode.'
        ];
        switch ($mode) {
        case 'getnodes':
            $result = $this->getNodes();
            break;
        case 'addnode':
            $result = $this->addNode($array['url'], $array['uuid']);
            break;
        case 'deletenode':
            $result = $this->deleteNode($array['url']);
            break;
        case 'getblockchain':
            $result = $this->getBlockchain();
            break;
        case 'mine':
            $result = $this->mine();
            break;
        case 'addtransaction':
            $result = $this->addTransaction(
                $array['sender'],
                $array['receiver'],
                $array['amount']
            );
            break;
        case 'resolveConflicts':
            $result = $this->resolveConflicts();
        }
        echo json_encode($result);
        exit;
    }

    /**
     * ノード全体を取得する
     */
    public function getNodes()
    {
        $nodes = ORM::for_table('nodes')->find_many();
        if ($nodes === false) {
            return [
                'error' => 'Failed to get nodes.'
            ];
        }
        $result = [];
        foreach ($nodes as $node) {
            $result[] = [
                'url' => $node->url,
                'uuid' => $node->uuid,
            ];
        }
        return $result;
    }

    /**
     * ノードを追加する
     */
    public function addNode($url, $uuid)
    {
        if (is_null($url) || $url === '') {
            return [
                'error' => 'Please specify the URL.'
            ];
        }
        if (is_null($uuid) || $uuid === '') {
            return [
                'error' => 'Please specify the UUID.'
            ];
        }
        $node = ORM::for_table('nodes')->where(['url' => $url])->find_one();
        if ($node) {
            return [
                'error' => 'The specified node already exists.'
            ];
        }
        $createNode = ORM::for_table('nodes')->create();
        $createNode->url = $url;
        $createNode->uuid = $uuid;
        if ($createNode->save()) {
            return [
                'addNode' => 'Success',
            ];
        }
        return [
            'error' => 'Failed to add node.'
        ];
    }

    /**
     * ノードを削除する
     */
    public function deleteNode($url)
    {
        if (is_null($url) || $url === '') {
            return [
                'error' => 'Please specify the URL.'
            ];
        }
        $deleteNode = ORM::for_table('nodes')
            ->where(['url' => $url])
            ->find_one();
        if ($deleteNode === false) {
            return [
                'error' => 'The specified node did not exist.'
            ];
        }
        if ($deleteNode->delete()) {
            return [
                'deleteNode' => 'Success'
            ];
        }
        return [
            'error' => 'Failed to delete node.'
        ];
    }

    /**
     * ブロックチェーン（ブロック全体）を取得
     **/
    public function getBlockchain()
    {
        $blocks = ORM::for_table('blocks')->find_many();
        if ($blocks === false) {
            return [
                'error' => 'Failed to get blockchain.'
            ];
        }
        $result = [];
        foreach ($blocks as $block) {
            $transactions = ORM::for_table('transactions')
                ->where(['blockId'=>$block->id])
                ->find_many();
            $subResult = [];
            if ($transactions) {
                foreach ($transactions as $transaction) {
                    $subResult[] = [
                        'id' => $transaction->id,
                        'blockId' => $transaction->blockId,
                        'sender' => $transaction->sender,
                        'receiver' => $transaction->receiver,
                        'amount' => $transaction->amount,
                        'timestamp' => $transaction->timestamp,
                    ];
                }
            }
            $result[] = [
                'id' => $block->id,
                'previousHash' => $block->previousHash,
                'proof' => $block->proof,
                'transactions' => $subResult,
                'timestamp' => $block->timestamp,
            ];
        }
        return $result;
    }

    /**
     * ブロックチェーンを採掘する
     * 
     * 新規ブロックを作成する
     */
    public function mine()
    {
        ORM::get_db()->beginTransaction();
        $createBlock = ORM::for_table('blocks')->create();
        $lastBlock = $this->_getLastBlock();
        if (array_key_exists('error', $lastBlock)) {
            ORM::get_db()->rollBack();
            return $lastBlock;
        }
        $createBlock->previousHash = $this->_getHash($lastBlock);
        $createBlock->proof = $this->_proofOfWork($lastBlock['proof']);
        $result = $createBlock->save();
        if ($result === false) {
            ORM::get_db()->rollBack();
            return [
                'error' => 'Failed to mine block.'
            ];
        }
        $result = $this->addTransaction('0', $this->_getUuid(), 1);
        if (array_key_exists('error', $result)) {
            ORM::get_db()->rollBack();
            return $result;
        }
        ORM::get_db()->commit();
        return $this->_getLastBlock();
    }

    /**
     * トランザクションを追加する
     */
    public function addTransaction($sender, $receiver, $amount)
    {
        if (is_null($sender) || $sender === '') {
            return [
                'error' => 'Please specify the sender.'
            ];
        }
        if (is_null($receiver) || $receiver === '') {
            return [
                'error' => 'Please specify the receiver.'
            ];
        }
        if (is_null($amount) || $amount === '') {
            return [
                'error' => 'Please specify the amount.'
            ];
        }
        $createTransaction = ORM::for_table('transactions')->create();
        $lastBlock = $this->_getLastBlock();
        if (array_key_exists('error', $lastBlock)) {
            return $lastBlock;
        }
        $createTransaction->blockId = $lastBlock['id'];
        $createTransaction->sender = $sender;
        $createTransaction->receiver = $receiver;
        $createTransaction->amount = $amount;
        if ($createTransaction->save()) {
            return [
                'addTransaction' => 'Success'
            ];
        }
        return [
            'error' => 'Failed to add transaction.'
        ];
    }

    /**
     * ブロックチェーンコンフリクトを解決する
     * 
     * @return 解決実行時 true, 解決未実行時 false
     */
    public function resolveConflicts()
    {
        $nodes = $this->getNodes();
        if (array_key_exists('error', $nodes)) {
            return [
                'error' => 'The nodes does not exist.'
            ];
        }
        $newChain = [];
        $maxLength = count($this->getBlockchain());
        foreach ($nodes as $node) {
            if ($node['url'] === NODE_URL) {
                continue;
            }
            $json = $this->_curl($node['url'] . '?mode=getblockchain');
            $chain = json_decode($json, true);
            $length = count($chain);
            if ($maxLength < $length && $this->_validChain($chain)) {
                $newChain = $chain;
                $maxLength = $length;
            }
        }
        if (empty($newChain)) {
            return [
                'resolveConflicts' => 'No conflicts'
            ];
        }
        $this->_setBlockchain($newChain);
        return [
            'resolveConflicts' => 'Success'
        ];
    }

    /**
     * DB の初期化
     */
    private function _initDb()
    {
        $db = ORM::get_db();
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `blocks` (
            `id` int(10) NOT NULL AUTO_INCREMENT,
            `previousHash` varchar(64) NOT NULL,
            `proof` int(10) NOT NULL,
            `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `transactions` (
                `id` int(10) NOT NULL AUTO_INCREMENT,
                `blockId` int(10) NOT NULL,
                `sender` varchar(64) NOT NULL,
                `receiver` varchar(64) NOT NULL,
                `amount` int(10) NOT NULL,
                `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS `nodes` (
                `id` int(10) NOT NULL AUTO_INCREMENT,
                `url` varchar(255) NOT NULL,
                `uuid` varchar(32) NOT NULL,
                `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        );
        return true;
    }

    /**
     * ノードリストの初期化
     **/
    private function _initBc()
    {
        $nodes = $this->getNodes();
        if (array_key_exists('error', $nodes) === false && empty($nodes) === false) {
            return;
        }
        $ormNodes = ORM::for_table('nodes');
        $createNode = $ormNodes->create();
        $createNode->url = NODE_URL;
        $createNode->uuid = $this->_getUuid();
        $createNode->save();
        $this->_addGenesisBlock();
        if (NEIGHBOUR_NODE_URL === '') {
            return;
        }
        $json = $this->_curl(NEIGHBOUR_NODE_URL . '?mode=getnodes');
        $addNodes = [];
        if ($json) {
            $addNodes = json_decode($json, true);
        }
        if (array_key_exists('error', $addNodes) || empty($addNodes)) {
            return;
        }
        foreach ($addNodes as $addNode) {
            $this->addNode($addNode[ 'url' ], $addNode[ 'uuid' ]);
        }
        $this->_diffusionAddNode();
        $this->resolveConflicts();
    }

    /**
     * 自身のノード URL を各ノードに追加 URL として拡散する
     **/
    private function _diffusionAddNode()
    {
        $nodes = $this->getNodes();
        if (array_key_exists('error', $nodes) || empty($nodes)) {
            return;
        }
        foreach ($nodes as $node) {
            $nodeUrl = $node['url'];
            if ($nodeUrl === NODE_URL) {
                continue;
            }
            $json = $this->_curl(
                $nodeUrl . '?mode=addnode',
                [
                    'url' => NODE_URL,
                    'uuid' => $this->_getUuid()
                ]
            );
            if ($json) {
                $array = json_decode($json, true);
                if (is_null($array) === false && in_array('Success', $array, true)) {
                    continue;
                }
            }
            $deleteNode = ORM::for_table('nodes')
                ->where(['url' => $nodeUrl])
                ->find_one();
            $deleteNode->delete();
            $this->_diffusionDeleteNode($nodeUrl);
        }
    }

    /**
     * 指定したノード URL を各ノードに削除 URL として拡散する
     **/
    private function _diffusionDeleteNode($url)
    {
        $nodes = $this->getNodes();
        if (array_key_exists('error', $nodes) || empty($nodes)) {
            return;
        }
        foreach ($nodes as $node) {
            if ($node['url'] === NODE_URL || $node['url'] === $url) {
                continue;
            }
            $this->_curl(
                $node['url'] . '?mode=deletenode',
                [ 'url' => $url ]
            );
        }
    }

    /**
     * 最新ブロックを取得
     * 
     * @return 最新ブロック（トランザクション含む）
     **/
    private function _getLastBlock()
    {
        $block = ORM::for_table('blocks')
            ->order_by_desc('id')
            ->find_one();
        if ($block === false) {
            return [
                'error' => 'Failed to get block.',
            ];
        }
        $transactions = ORM::for_table('transactions')
            ->where(['blockId'=>$block->id])
            ->find_many();
        $subResult = [];
        if ($transactions) {
            foreach ($transactions as $transaction) {
                $subResult[] = [
                    'id' => $transaction->id,
                    'blockId' => $transaction->blockId,
                    'sender' => $transaction->sender,
                    'receiver' => $transaction->receiver,
                    'amount' => $transaction->amount,
                    'timestamp' => $transaction->timestamp,
                ];
            }
        }
        return [
            'id' => $block->id,
            'previousHash' => $block->previousHash,
            'proof' => $block->proof,
            'transactions' => $subResult,
            'timestamp' => $block->timestamp
        ];
    }

    /**
     * ブロックチェーンの置き換え
     **/
    private function _setBlockchain($chain)
    {
        if (empty($chain)) {
            return false;
        }
        ORM::get_db()->beginTransaction();
        $ormBlocks = ORM::for_table('blocks');
        $result = $ormBlocks->delete_many();
        if ($result === false) {
            ORM::get_db()->rollBack();
            return false;
        }
        $ormTransactions = ORM::for_table('transactions');
        $result = $ormTransactions->delete_many();
        if ($result === false) {
            ORM::get_db()->rollBack();
            return false;
        }
        foreach ($chain as $block) {
            $createBlock = $ormBlocks->create();
            $createBlock->id = $block['id'];
            $createBlock->proof = $block['proof'];
            $createBlock->previousHash = $block['previousHash'];
            $createBlock->timestamp = $block['timestamp'];
            $result = $createBlock->save();
            if ($result === false) {
                ORM::get_db()->rollBack();
                return false;
            }
            foreach ($block['transactions'] as $transaction) {
                $createTransaction = $ormTransactions->create();
                $createTransaction->id = $transaction['id'];
                $createTransaction->blockId = $transaction['blockId'];
                $createTransaction->sender = $transaction['sender'];
                $createTransaction->receiver = $transaction['receiver'];
                $createTransaction->amount = $transaction['amount'];
                $createTransaction->timestamp = $transaction['timestamp'];
                $result = $createTransaction->save();
                if ($result === false) {
                    ORM::get_db()->rollBack();
                    return false;
                }
            }
        }
        $this->_setAutoIncrement('blocks', ++$block['id']);
        $this->_setAutoIncrement('transactions', ++$transaction['id']);
        ORM::get_db()->commit();
        return true;
    }

    /**
     * 始祖ブロックを追加
     **/
    private function _addGenesisBlock()
    {
        $createBlock = ORM::for_table('blocks')->create();
        $createBlock->id = 1;
        $createBlock->proof = 1;
        $createBlock->previousHash = '0000';
        $createBlock->save();
    }

    /**
     * チェーン全体を確認
     **/
    private function _validChain($chain)
    {
        if (count($chain) < 2) {
            return false;
        }
        $lastBlock = $this->_arrayLast($chain);
        $currentIndex = count($chain) - 2;
        while ($currentIndex > -1) {
            $block = $chain[ $currentIndex ];
            if ($this->_getHash($block) !== $lastBlock['previousHash']) {
                return false;
            }
            if ($this->_validProof($block['proof'], $lastBlock['proof']) === false) {
                return false;
            }
            $lastBlock = $block;
            --$currentIndex;
        }
        return true;
    }

    /**
     * プルーフ・オブ・ワークを実行
     * 
     * @return プルーフ
     **/
    private function _proofOfWork(int $lastProof)
    {
        $proof = 0;
        while ($this->_validProof($lastProof, $proof) === false) {
            ++$proof;
        }
        return $proof;
    }

    /**
     * オートインクリメントの値を設定する
     */
    private function _setAutoIncrement(string $table, int $value)
    {
        $tableList = [
            'blocks',
            'transactions',
            'nodes'
        ];
        if (in_array($table, $tableList, true) === false) {
            return false;
        }
        if (is_int($value) === false) {
            return false;
        }
        $db = ORM::get_db();
        $db->exec("ALTER TABLE $table AUTO_INCREMENT = $value;");
        return true;
    }

    /**
     * プルーフが正しいか検証
     **/
    private function _validProof(int $lastProof, int $proof)
    {
        $nonce = $lastProof . $proof;
        $hash = hash('sha256', $nonce);
        return substr($hash, -4) === '0000';
    }

    /**
     * ハッシュ生成
     **/
    private function _getHash($block)
    {
        return hash('sha256', json_encode($block));
    }

    /**
     * UUID 取得
     * 
     * @return UUID が未設定の場合は UUID を生成し、
     *         設定済みの場合はその UUID を返す
     **/
    private function _getUuid()
    {
        $node = ORM::for_table('nodes')
            ->where(['url' => NODE_URL])
            ->find_one();
        if ($node) {
            return $node->uuid;
        }
        return str_replace('-', '', Uuid::uuid4());
    }
    
    /**
     * 配列の最後の値を取得
     **/
    private function _arrayLast(array $array)
    {
        return end($array);
    }

    /**
     * curl 実行
     */
    private function _curl($url, $array = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($array));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_URL, $url);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
}