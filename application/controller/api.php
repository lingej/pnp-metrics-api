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
        if ( preg_match("$query", $value['name']) ){
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
      return_json($data, 901);
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
      // generate a list of hosts
      if ( isRegex($host) ){
         // create a Host List
         $hosts = $this->data->getHosts();
         $t = array();
         foreach ($hosts as $value) {
           if ( preg_match("$host", $value['name']) ){
             $t[] = $value['name'];
           }
         }
         $hosts = $t;
      }else{
         $hosts[0] = $host;
      }

      $hk = 0; // Host Key
      foreach ( $hosts as $host){

        if ( isRegex($service) ){
           // create a Host List
           $services = $this->data->getServices($host);
           $t = array();
           foreach ($services as $value) {
             if ( preg_match("$service", $value['name']) ){
               $t[] = $value['name'];
             }
           }
           $services = $t;
        }else{
           $services[0] = $service;
        }

        foreach ( $services as $service){
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
            if( $v == $perflabel."_".$type){
              $index = $i;
              break;
            }
            $i++;
          }

          $start                  = (string) $xpd->meta->start;
          $end                    = (string) $xpd->meta->end;
          $step                   = (string) $xpd->meta->step;
          $data['targets'][$key][$hk]['start']       = $start * 1000;
          $data['targets'][$key][$hk]['end']         = $end * 1000;
          $data['targets'][$key][$hk]['host']        = $host;
          $data['targets'][$key][$hk]['service']     = $service;
          $data['targets'][$key][$hk]['perflabel']   = $perflabel;
          $data['targets'][$key][$hk]['type']        = $type;

          $i  = 0;
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
            $data['targets'][$key][$hk]['datapoints'][] = array( $d, $timestamp );
            $i++;
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
   if ( substr($string,0,1) == "/" ){
      return true;
   }else{
      return false;
   }
}
