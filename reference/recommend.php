<?php
class Recommender
{
	var $recommendation;

	function __construct($mainFile) {
	   $this->recommendation = "python ".$mainFile;
	}

	function getRecommendation($json)
	{
		$input = json_decode($json);
		$text = $input[0].$input[1].$input[2];
	    $command = escapeshellcmd($this->recommendation." $text");
	    $output = shell_exec($command);
	    return $output;
	}
}
?>
