<?php

use Slim\Http\Request;
use Slim\Http\Response;

// Untuk Krisak only, for temporarily
use GuzzleHttp\Client;

// Routes

$app->post('/', function (Request $req, Response $res, array $args) {
    $this->logger->info($req->getHeaderLine('Content-Type'));
    $ct = $req->getContentType();
    if ('application/json' != $ct) {
        $ret["ok"] = "false";
        $ret["reason"] = "Wrong Content-Type";
        return $res->write(json_encode($ret, JSON_UNESCAPED_SLASHES));
    }
    $body = $req->getBody();
    if (! isJson($body)) {
        $ret["ok"] = "false";
        $ret["reason"] = "Invalid JSON";
        return $res->write(json_encode($ret, JSON_UNESCAPED_SLASHES));
    }
    $data = json_decode($body, true);
    $this->logger->info($data['device']);
    $sql = "INSERT INTO raw (content) VALUES (:content)";
    $ret = [];
    try {
        // Simpan ke table 'raw'
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute(["content" => $body]);
        $ret["ok"] = "true";
    } catch (Exception $e) {
        $ret["ok"] = "false";
        $ret["reason"] = $e->getMessage();
    	return $res->write(json_encode($ret, JSON_UNESCAPED_SLASHES));
    }
    
    try {
        $MQTT_HOST = "mqtt.bbws-bsolo.net";
        $MQTT_PORT = 14983;
        $MQTT_CLIENT = "prinus.net";
        // Distribusi data, kirim ke MQTT Broker
        $i_sn = explode('/', $data['device'])[1];
        $sql = "SELECT t.slug AS slug, t.id AS id FROM tenant t, logger l WHERE l.sn=:sn AND l.tenant_id=t.id";
        $stmt = $this->db->prepare($sql);
        $result = $stmt->execute(["sn" => $i_sn]);
        if ($result == -1) {
            $row = $stmt->fetch();
            $mqtt = new Bluerhinos\phpMQTT($MQTT_HOST, $MQTT_PORT, $MQTT_CLIENT);
            $mqtt->connect();
            $mqtt->publish($row["slug"], $body);
            $mqtt->close();
            $this->logger->info("published: topic=" . $row["slug"] . "msg=" . $body);
        }
    } catch (Exception $e) {
        $ret["ok"] = "false";
        $ret["reason"] = $e->getMessage();
    }

    try {
        // proses khusus
        $setting = getSetting($i_sn, $this->db);
        $logger_setting = json_decode($setting['item'], true);
        if ($data['sensor'] == 'KRG-10') {
            // uWave Sonar KRG-10, 4-20mA, 4mA pd 30meter, 20mA pd 1meter
            // hitung 'wlevel', hilangkan 'wl_scale'
            $wlevel = ($logger_setting['x1'] * 100.0) - ($data['wl_scale'] - $logger_setting['y1']) * $logger_setting['factor'];
            unset($data['wl_scale']);
            $data['wlevel'] = $wlevel; // dalam centimeter
        }
        // Khusus: Temporary, Data Krisak di kirim ke bbws-bsolo.net
        // HTTP POST dg Guzzle
        // $base_uri = 'http://localhost:3000';
        $base_uri = 'http://iot.bbws-bsolo.net/sensors';
        $this->logger->info('base_uri:' . $base_uri);
        $client = new Client();
        $r = $client->request('POST', $base_uri, [
            'json' => $data
        ]);
        //$this->logger->info('Request POST :' . $base_uri .' $result: ' . $r);

    } catch (Exception $e) {
        $ret["ok"] = "false";
        $ret["reason"] = $e->getMessage();
    }
    return $res->write(json_encode($ret, JSON_UNESCAPED_SLASHES));
});

$app->get('/', function (Request $request, Response $response, array $args) {
    // Sample log message
    $this->logger->info("/ route");

    // Render index view
    return $this->renderer->render($response, 'index.phtml', $args);
});

function isJson($string) {
    json_decode($string);
    return (json_last_error() == JSON_ERROR_NONE);
};

function getSetting($sn, $pdo) {
    $stmt = $pdo->prepare("SELECT item FROM setting WHERE sn=:sn");
    $stmt->bindParam(':sn', $sn, PDO::PARAM_STR);
    $stmt->execute();
    while ($row = $stmt->fetch()) {
        return $row;
        break;
    }
    
}
