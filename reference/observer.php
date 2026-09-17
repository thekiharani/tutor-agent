<?php
class local_tutoragent_observer {
    /*public static function user_loggedin(\core\event\user_loggedin $event) {
        echo "<script>alert('logged in');</script>";      //call custom function
        $event_data = $event->get_data();
        var_dump($event_data);
    die();
    }
    public static function user_loggedout(\core\event\user_loggedout $event) {
        echo "<script>alert('logged out');</script>";      //call custom function
        $event_data = $event->get_data();
        var_dump($event_data);
    die();
    }
    public static function course_module_created(\core\event\course_module_created $event) {
        echo "<script>alert('Module Created');</script>";
        $event_data = $event->get_data();
        var_dump($event_data);
    //die();
    }
    public static function content_viewed(\core\event\content_viewed $event) {
        echo "<script>alert('Content Viewed');</script>";
        $event_data = $event->get_data();
        var_dump($event_data);
    //die();
    }*/
    public static function course_module_viewed(\core\event\course_module_viewed $event) {
    	global $DB;
    	require("recommend.php");
        // echo "<script>alert('Module Viewed');</script>";
        // $event_data = $event->get_data();
        // var_dump($event->get_description());
        // $arr = json_decode($event,true);

  		// foreach($event as $key => $value) {
		//   echo $key . " => " . $value . "<br>";
		// }

    	//get Vark value from DB
    	$vark = 0;

  		$table = $event->objecttable;
  		$objectid = $event->objectid;
  		$courseid = $event->courseid;
  		$userid = $event->userid;
  		// echo "Table: mdl_".$table.'<br>Moduleid: '.$objectid.'<br>Courseid: '.$courseid.'<br>Userid: '.$userid;

  		$res = $DB->get_record($table, array(), $fields='name,intro', $strictness=IGNORE_MISSING);
  		// var_dump($res);
  		$arr = array($res->name,trim($res->intro),$vark);
		$array = json_encode($arr);

		$recommender = new Recommender("main.py");
        $response = $recommender->getRecommendation($array);
        echo $response;
        //Pass response to the information agent

    }
}
?>
