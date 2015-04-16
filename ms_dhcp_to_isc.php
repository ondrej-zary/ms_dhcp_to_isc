<?php
function range_exclude($ranges, $start, $end) {
	$start = (float)sprintf("%u",ip2long($start));
	$end = (float)sprintf("%u",ip2long($end));

	$new_ranges = array();
	foreach ($ranges as $range) {
		$range["start"] = (float)sprintf("%u",ip2long($range["start"]));
		$range["end"] = (float)sprintf("%u",ip2long($range["end"]));

		if ($range["start"] > $end || $range["end"] < $start)		/* no overlap */
			$new_ranges[] = $range;
		else if ($range["start"] >= $start && $range["end"] > $end)	/* partial overlap at start */
			$new_ranges[] = array("start" => $end + 1, "end" => $range["end"]);
		else if ($range["start"] < $start && $range["end"] > $end) {	/* inside */
			$new_ranges[] = array("start" => $range["start"], "end" => $start - 1);
			$new_ranges[] = array("start" => $end + 1, "end" => $range["end"]);
		} else if ($range["start"] < $start && $range["end"] <= $end)	/* partial overlap at end */
			$new_ranges[] = array("start" => $range["start"], "end" => $start - 1);
		/* else full overlap => nothing */
	}
	$ranges = array();
	foreach ($new_ranges as $range)
		$ranges[] = array("start" => long2ip($range["start"]), "end" => long2ip($range["end"]));

	return $ranges;
}

function fill_option($option, $value) {
	$quote = "";
	if ($option["type"] == "text")
		$quote = "\"";

	return "option ".$option["name"]." ".$quote.$value.$quote.";\n";
}

function hostname_filter(&$hostnames, $hostname) {
	$hostname = (string)$hostname;
	/* allow only alphanumeric characters, dot, minus and underscore */
	for ($i = 0; $i < strlen($hostname); $i++)
		if (!ctype_alnum($hostname[$i]) && $hostname[$i] != "." && $hostname[$i] != "-")
			$hostname[$i] = "_";
	/* make sure all hostnames are unique */
	if (in_array($hostname, $hostnames))
		$hostname .= "_";
	$hostnames[] = $hostname;

	return $hostname;
}

	$supported_options = array(
		3   => array("type" => "ip",	"name" => "routers"),
		4   => array("type" => "ip",	"name" => "time-servers"),
		6   => array("type" => "ip",	"name" => "domain-name-servers"),
		15  => array("type" => "text",	"name" => "domain-name"),
		42  => array("type" => "ip",	"name" => "ntp-servers"),
//		51  => 
//		81
		150 => array("type" => "ip",	"name" => "cisco-tftp-server"),
	);
	$hostnames = array();

	$xml = simplexml_load_file($argv[1]);
	if ($xml === FALSE)
		die("Error loading XML file\n");

	echo "option cisco-tftp-server code 150 = { ip-address };\n";

	$option_defs = array();
	foreach ($xml->IPv4->OptionDefinitions->OptionDefinition as $definition)
		$option_defs[(string)$definition->OptionId] = (string)$definition->DefaultValue;

	$options = array();
	foreach ($xml->IPv4->OptionValues->OptionValue as $option)
		$options[(string)$option->OptionId] = (string)$option->Value;

	foreach ($options as $option => $value)
		if (array_key_exists($option, $supported_options))
			echo fill_option($supported_options[$option], $value);
		else
			echo "#unsupported option ".$option." value ".$value."\n";

	foreach ($xml->IPv4->Scopes->Scope as $scope) {
		echo "\n";
		echo "#".$scope->Name."\n";
		if (!empty($scope->Description))
			echo "#".$scope->Description."\n";
		echo "subnet ".$scope->ScopeId." netmask ".$scope->SubnetMask." {\n";

		/* convert range & exclusions into multiple ranges */
		$ranges = array(array("start" => (string)$scope->StartRange, "end" => (string)$scope->EndRange));
		if (isset($scope->ExclusionRanges))
			foreach ($scope->ExclusionRanges->IPRange as $exclusion)
				$ranges = range_exclude($ranges, (string)$exclusion->StartRange, (string)$exclusion->EndRange);
		foreach ($ranges as $range)
			echo "\trange ".$range["start"]." ".$range["end"].";\n";

		/* scope options */
		if (isset($scope->OptionValues))
			foreach($scope->OptionValues->OptionValue as $option)
				$scope_options[(string)$option->OptionId] = (string)$option->Value;
		foreach ($scope_options as $option => $value)
			if (array_key_exists($option, $supported_options))
				echo "\t".fill_option($supported_options[$option], $value);
			else
				echo "\t#unsupported option ".$option." value ".$value."\n";

		/* reservations */
		if (isset($scope->Reservations)) {
			foreach ($scope->Reservations->Reservation as $reservation) {
				echo "\n";
				if (!empty($reservation->Description))
					echo "\t#".$reservation->Description."\n";
				echo "\thost ".hostname_filter($hostnames, $reservation->Name)." {\n";
				echo "\t\thardware ethernet ".str_replace('-', ':', $reservation->ClientId).";\n";
				echo "\t\tfixed-address ".$reservation->IPAddress.";\n";
				echo "\t}\n";
			}
		}

		echo "}\n";
	}
?>