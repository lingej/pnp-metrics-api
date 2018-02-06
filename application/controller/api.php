<?php defined('SYSPATH') OR die('No direct access allowed.');
/**
* API controller.
*
* @package    pnp4nagios
* @author     Joerg Linge
* @license    GPL
*/
class Api_Controller extends System_Controller  {

  public function __construct(){
    parent::__construct();
    // Disable auto-rendering
    $this->auto_render = FALSE;
    $this->data->getTimeRange($this->start,$this->end,$this->view);
    // Graphana sends JSON via POST
    $this->post_data = json_decode(file_get_contents('php://input'), true);

  }

  public function index() {
    $data['pnp_version']  = PNP_VERSION;
    $data['pnp_rel_date'] = PNP_REL_DATE;
    $data['error']        = "";
    return_json($data, 200);
  }

  /*
  *
  *
  */
  public function hosts($query = false) {
    $data  = array();
    $hosts = getHosts($this->data, $query);
    foreach ( $hosts as $host ){
      $data['hosts'][] = array(
        'name' => $host
      );
    }
    return_json($data, 200);
  }

  /*
  *
  *
  */
  public function services() {
    $data  = array();
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      // Only Post Reuests
      $data['error'] = "Only POST Requests allowed";
      return_json($data, 901);
      return;
    }
    $pdata = json_decode(file_get_contents('php://input'), TRUE);

    $host = arr_get($pdata, "host");
    if ( $host === false ){
      $data['error'] = "No hostname specified";
      return_json($data, 901);
      return;
    }
    $services   = array();
    $hosts      = getHosts($this->data, $host);
    $services   = getServices($this->data, $hosts);
    $duplicates = array();

    foreach($services as $service){
      // skip duplicates
      if(isset($duplicates[$service['servicedesc']])) {
        continue;
      }
      $duplicates[$service['servicedesc']] = true;
      $data['services'][] = array(
        'name'        => $service['name'],
        'servicedesc' => $service['servicedesc'],
        'hostname'    => $service['hostname']
      );
    }
    return_json($data, 200);
  }

  /*
  *
  *
  */
  public function labels ( $host=false, $service=false ) {
    $data = array();
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      // Only Post Reuests
      $data['error'] = "Only POST Requests allowed";
      return_json($data, 901);
      return;
    }
    $pdata    = json_decode(file_get_contents('php://input'), TRUE);
    $host     = arr_get($pdata, "host");
    $service  = arr_get($pdata, "service");

    if ( $host === false ){
      $data['error'] = "No hostname specified";
      return_json($data, 901);
      return;
    }
    if ( $service === false ){
      $data['error'] = "No service specified";
      return_json($data, 901);
      return;
    }

    $hosts      = getHosts($this->data, $host);
    $services   = getServices($this->data, $hosts, $service);
    $duplicates = array();

    foreach($services as $service){
      try {
        // read XML file
        $this->data->readXML($service['hostname'] == "pnp-internal" ? ".pnp-internal" : $service['hostname'], $service['name']);
      } catch (Kohana_Exception $e) {
        $data['error'] = "$e";
        return_json($data, 901);
        return;
      }

      foreach( $this->data->DS as $KEY => $DS) {
        // skip duplicates
        if(isset($duplicates[$DS['LABEL']])) {
          continue;
        }
        $duplicates[$DS['LABEL']] = true;
        $data['labels'][] = array(
          'name'     => $DS['NAME'],
          'label'    => $DS['LABEL'],
          'service'  => $service['name'],
          'hostname' => $service['hostname']
        );
      }
    }
    return_json($data, 200);
  }


  public function metrics(){
    // extract metrics for a given datasource
    // TODO Multiple sources via regex
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
      // Only Post Reuests
      $data['error'] = "Only POST Requests allowed";
      return_json($data, 901);
      return;
    }
    $hosts    = array(); // List of all Hosts
    $services = array(); // List of services for a given host
    $pdata    = json_decode(file_get_contents('php://input'), TRUE);
    $data     = array();

    if ( !isset($pdata['targets']) ){
      $data['error'] = "No targets specified";
      return_json($data, 901);
      return;
    }

    foreach( $pdata['targets'] as $key => $target){

      $this->data->TIMERANGE['start'] = arr_get($pdata,  'start');
      $this->data->TIMERANGE['end']   = arr_get($pdata,  'end');
      $host        = arr_get($target, 'host');
      $service     = arr_get($target, 'service');
      $perflabel   = arr_get($target, 'perflabel');
      $type        = arr_get($target, 'type');
      $refid       = arr_get($target, 'refid');
      if ( $host === false ){
        $data['error'] = "No hostname specified";
        return_json($data, 901);
        return;
      }
      if ( $service === false ){
        $data['error'] = "No service specified";
        return_json($data, 901);
        return;
      }
      if ( $perflabel === false ){
        $data['error'] = "No perfdata label specified";
        return_json($data, 901);
        return;
      }
      if ( $type === false ){
        $data['error'] = "No perfdata type specified";
        return_json($data, 901);
        return;
      }
      $hosts    = getHosts($this->data, $host);
      $services = getServices($this->data, $hosts, $service);

      $hk = 0; // Host Key

      foreach ( $services as $service) {
        $host    = $service['hostname'];
        $service = $service['name'];
        try {
          // read XML file
          $this->data->readXML(($host == "pnp-internal" ? ".pnp-internal" : $host), $service);
        } catch (Kohana_Exception $e) {
          $data['error'] = "$e";
          return_json($data, 901);
          return;
        }

        // create a Perflabel List
        $perflabels = array();
        foreach( $this->data->DS as $value){
          $label = arr_get($value, "LABEL" );
          if (isRegex($perflabel)) {
              if(!preg_match( $perflabel, $label ) ){
                continue;
              }
          } elseif ( $perflabel != $label ) {
            continue;
          }
          $perflabels[] = array(
                            "label" => arr_get($value, "NAME" ),
                            "warn"  => arr_get($value, "WARN" ),
                            "crit"  => arr_get($value, "CRIT" )
          );
        }

        foreach ( $perflabels as $tmp_perflabel){
          try {
            $this->data->buildXport($host == "pnp-internal" ? ".pnp-internal" : $host, $service);
            $xml = $this->rrdtool->doXport($this->data->XPORT);
          } catch (Kohana_Exception $e) {
            $data['error'] = "$e";
            return_json($data, 901);
            return;
          }

          $xpd   = simplexml_load_string($xml);
          $i = 0;
          $index = -1;
          foreach ( $xpd->meta->legend->entry as $k=>$v){
            if($type == "WARNING" || $type == "CRITICAL") {
              if( $v == $tmp_perflabel['label']."_AVERAGE"){
                $index = $i;
                break;
              }
            }
            else {
              if( $v == $tmp_perflabel['label']."_".$type){
                $index = $i;
                break;
              }
            }
            $i++;
          }
          if ( $index === -1 ){
            $data['error'] = "No perfdata found for ".$tmp_perflabel['label']."_".$type;
            return_json($data, 901);
            return;
          }

          $start                  = (string) $xpd->meta->start;
          $end                    = (string) $xpd->meta->end;
          $step                   = (string) $xpd->meta->step;
          $data['targets'][$key][$hk]['start']       = $start * 1000;
          $data['targets'][$key][$hk]['end']         = $end * 1000;
          $data['targets'][$key][$hk]['host']        = $host;
          $data['targets'][$key][$hk]['service']     = $service;
          $data['targets'][$key][$hk]['perflabel']   = $tmp_perflabel['label'];
          $data['targets'][$key][$hk]['type']        = $type;

          $i  = 0;
          if($type == "WARNING" || $type == "CRITICAL") {
            foreach ( $xpd->data->row as $row=>$value){
              // timestamp in milliseconds
              $timestamp = ( $start + $i * $step ) * 1000;
              if($type == "WARNING") {
                $d = floatval($tmp_perflabel['warn']);
              } else {
                $d = floatval($tmp_perflabel['crit']);
              }
              $data['targets'][$key][$hk]['datapoints'][] = array( $d, $timestamp );
              $i++;
            }
          } else {
            foreach ( $xpd->data->row as $row=>$value){
              // timestamp in milliseconds
              $timestamp = ( $start + $i * $step ) * 1000;
              $d = (string) $value->v->$index;
              if ($d == "NaN"){
                $d = null;
              }else{
                $d = floatval($d);
              }
              $data['targets'][$key][$hk]['datapoints'][] = array( $d, $timestamp );
              $i++;
            }
          }

          $hk++;

        }
      }
    }

    return_json($data, 200);
  }
}
/*
* return array key
*/
function arr_get($array, $key=false, $default=false){
  if ( isset($array) && $key == false ){
    return $array;
  }
  $keys = explode(".", $key);
  foreach ($keys as $key_part) {
    if ( isset($array[$key_part] ) === false ) {
      if (! is_array($array) or ! array_key_exists($key_part, $array)) {
        return $default;
      }
    }
    $array = $array[$key_part];
  }
  return $array;
}

/*
*
*/
function return_json( $data, $status=200 ){
  $json = json_encode($data);
  header('Status: '.$status);
  header('Content-type: application/json');
  print $json;
}

function isRegex($string){
  // if string looks like an regex /regex/
  if ( substr($string,0,1) == "/" && substr($string,-1,1) == "/" && strlen($string) >= 2 ){
    return true;
  }else{
    return false;
  }
}

function getHosts($data, $query = false) {
  $result  = array();
  $hosts   = $data->getHosts();
  $isRegex = false;
  if ($query !== false && isRegex($query) ) {
    $isRegex = true;
  }
  foreach ( $hosts as $host ){
    if ( $host['state'] != 'active' ){
      continue;
    }
    if($isRegex) {
      if(preg_match("$query", $host['name']) ) {
        $result[] = $host['name'];
      }
    }
    elseif ($query !== false) {
      if("$query" == $host['name']) {
        $result[] = $host['name'];
      }
    } else {
      $result[] = $host['name'];
    }
  }
  if ($query !== false && $query == ".pnp-internal") {
    $result[] = ".pnp-internal";
  }
  return($result);
}

/*
* returns list of service hashes
*/
function getServices($data, $hosts, $query = false) {
  $result = array();
  $isRegex = false;
  if ($query !== false && isRegex($query) ) {
    $isRegex = true;
  }
  foreach ( $hosts as $host){
    $services = $data->getServices($host);
    foreach ($services as $value) {
      if ($isRegex) {
        if ( preg_match("$query", $value['name']) || preg_match("$query", $value['servicedesc'])) {
          $result[] = $value;
        }
      }
      elseif ($query !== false) {
        if("$query" == $value['name'] || "$query" == $value['servicedesc']) {
          $result[] = $value;
        }
      } else {
        $result[] = $value;
      }
    }
  }
  return($result);
}
