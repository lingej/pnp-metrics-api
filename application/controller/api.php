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

  public function hosts($query = false) {
    if ( $query ){
      $hosts = $this->data->getHosts();
      $data  = array();
      foreach ( $hosts as $host => $value ){
        if ( $value['state'] != 'active' ){
          continue;
        }
        if ( preg_match("/$query/i", $value['name']) ){
          $data['hosts'][] = array(
            'name' => $value['name']
          );
        }
      }
    }else{
      $hosts = $this->data->getHosts();
      $data  = array();
      foreach ( $hosts as $host => $value ){
        if ( $value['state'] != 'active' ){
          continue;
        }
        $data['hosts'][] = array(
          'name' => $value['name']
        );
      }
    }
    return_json($data, 200);
  }

  public function services($host = false, $query = false) {
    if ( $host === false ){
      $data['error'] = "No hostname specified";
      return_json($data, 901);
      return;
    }
    $services = array();
    try {
      $services = $this->data->getServices($host);
    } catch ( Kohana_Exception $e) {
      $data['error'] = "$e";
      return_json($data, 901);
      return;
    }

    foreach($services as $service => $value){
      if ( $query === false){
        // All Services
        $data['services'][] = array(
          'name' => $value['name']
        );
      }else{
        // Services matching Regex
        if ( preg_match( "/$query/i", $value['name']) ){
          $data['services'][] = array(
            'name' => $value['name']
          );
        }
      }
    }
    return_json($data, 200);
  }

  public function labels ( $host=false, $service=false ) {
    if ( $host === false ){
      $data['error'] = "No hostname specified";
      return_json($data, 901);
      return;
    }
    if ( $service === false ){
      $data['error'] = "No service specified";
      $json = json_encode($data, JSON_PRETTY_PRINT);
      header('Status: 901');
      header('Content-type: application/json');
      print $json;
      return;
    }
    try {
      // read XML file
      $this->data->readXML($host, $service);
    } catch (Kohana_Exception $e) {
      $data['error'] = "$e";
      return_json($data, 901);
      return;
    }
    $data = array();
    foreach( $this->data->DS as $KEY => $DS){
      $data['labels'][] = array(
        'name' => $DS['NAME']
      );
    }
    return_json($data, 200);
  }


  public function metrics(){
    // extract metrics vor a given datasource
    // TODO Multiple sources via regex
    $pdata = json_decode(file_get_contents('php://input'), TRUE);
    #print_r($pdata);
    $this->start = arr_get($pdata, 'start');
    $this->end   = arr_get($pdata, 'end');
    $host        = arr_get($pdata, 'targets.0.host');
    $service     = arr_get($pdata, 'targets.0.service');
    $perflabel   = arr_get($pdata, 'targets.0.perflabel');
    $type        = arr_get($pdata, 'targets.0.type');
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
    $data = array();

    try {
      $this->data->buildXport($host, $service);
      $xml = $this->rrdtool->doXport($this->data->XPORT);
    } catch (Kohana_Exception $e) {
      $data['error'] = "$e";
      return_json($data, 901);
      return;
    }

    $xpd = simplexml_load_string($xml);
    $i = 0;
    $index = 0;
    foreach ( $xpd->meta->legend->entry as $k=>$v){
      if( $v == $perflabel."_AVERAGE"){
        $index = $i;
        break;
      }
      $i++;
    }

    $i = 0;
    $start                  = (string) $xpd->meta->start;
    $end                    = (string) $xpd->meta->end;
    $step                   = (string) $xpd->meta->step;
    $data[0]['start']       = $start * 1000;
    $data[0]['end']         = $end * 1000;
    $data[0]['host']        = $host;
    $data[0]['service']     = $service;
    $data[0]['perflabel']   = $perflabel;


    foreach ( $xpd->data->row as $row=>$value){
        // timestamp in milliseconds
        $timestamp = ( $start + $i * $step ) * 1000;
        #print_r($value);i
        $d = (string) $value->v->$index;
        if ($d == "NaN"){
          $d = null;
        }else{
          $d = floatval($d);
        }
        $data[0]['datapoints'][] = array( $d, $timestamp );
        $i++;
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
  $json = json_encode($data, JSON_PRETTY_PRINT);
  header('Status: '.$status);
  header('Content-type: application/json');
  print $json;
}
