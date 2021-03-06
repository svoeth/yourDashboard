<?php
/********************************************************************
* This file is part of yourDashboard.
*
* Copyright 2014-2015 Michael Batz
*
*
* yourDashboard is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* yourDashboard is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with yourDashboard.  If not, see <http://www.gnu.org/licenses/>.
*
*********************************************************************/
namespace yourDashboard\dashboard\dashlets;

use yourDashboard\dashboard\Dashlet;
use yourDashboard\dashboard\DashletException;
use yourDashboard\connectors\ConnectorOpenNMS;

/**
* dashlet to show OpenNMS alarms
* @author: Michael Batz <michael@yourcmdb.org>
*/
class DashletOpenNMSAlarms extends Dashlet
{

	public function getHtmlContentString()
	{
		//get parameters
		$title = $this->parameter->getValue("title");
		$restUrl = $this->parameter->getValue("restUrl");
		$restUser = $this->parameter->getValue("restUser");
		$restPassword = $this->parameter->getValue("restPassword");
		$alarmsCategory = $this->parameter->getValueArray("alarmsCategory");
		$ueiFilter = $this->parameter->getValue("ueiFilter");
		$maxEntries = $this->parameter->getValue("maxEntries");
		$linkUrlBase = $this->parameter->getValue("linkUrlBase");
		$paramShowLogmessage = $this->parameter->getValue("showLogmessage");
		$paramMinSeverity = $this->parameter->getValue("minSeverity");
		$createAlarms = $this->parameter->getValue("createAlarms");
		$alarmMinTime = $this->parameter->getValue("alarmMinTime");
		$alarmMaxTime = $this->parameter->getValue("alarmMaxTime");

		//set default values
		if($alarmMinTime == "")
		{
			$alarmMinTime = 0;
		}
		if($alarmMaxTime == "")
		{
			$alarmMaxTime = 300;
		}

		//open connector
		$connector = new ConnectorOpenNMS($restUrl, $restUser, $restPassword);

		//get current outages as SimpleXmlObject
		$alarmsXml = simplexml_load_string($connector->getData("alarms?alarmAckUser=null&orderBy=lastEventTime&order=desc&limit=0"));
		if($alarmsXml === FALSE)
		{
			throw new DashletException("Error connecting to OpenNMS");
		}

		//if minSeverity is defined, get all allowed severities
		if($paramMinSeverity != "")
		{
			$severityFilter = Array();
			switch($paramMinSeverity)
			{
				case "CLEARED":
					$severityFilter = Array("CLEARED", "NORMAL", "INDETERMINATE", "WARNING", "MINOR", "MAJOR", "CRITICAL");
					break;
				case "NORMAL":
					$severityFilter = Array("NORMAL", "INDETERMINATE", "WARNING", "MINOR", "MAJOR", "CRITICAL");
					break;
				case "INDETERMINATE":
					$severityFilter = Array("INDETERMINATE", "WARNING", "MINOR", "MAJOR", "CRITICAL");
					break;
				case "WARNING":
					$severityFilter = Array("WARNING", "MINOR", "MAJOR", "CRITICAL");
					break;
				case "MINOR":
					$severityFilter = Array("MINOR", "MAJOR", "CRITICAL");
					break;
				case "MAJOR":
					$severityFilter = Array("MAJOR", "CRITICAL");
					break;
				case "CRITICAL":
					$severityFilter = Array("CRITICAL");
					break;
				default:
					$errorMessage = "Configuration Error: none of the allowed strings for parameter minSeverity set.";
					$errorMessage.= "(allowed strings are 'CLEARED', 'NORMAL', 'INDETERMINATE', 'WARNING', 'MINOR', 'MAJOR', 'CRITICAL')";
					throw new DashletException($errorMessage);
					break;
			}
		}

		//if category is defined, get all nodes of category
		if(count($alarmsCategory) > 0)
		{
			//get all nodes
			$nodeFilter = Array();
			$nodesXml = simplexml_load_string($connector->getData("nodes?limit=0"));
			if($nodesXml === FALSE)
			{
				throw new DashletException("Error connecting to OpenNMS");
			}

			foreach($nodesXml->xpath('//node') as $node)
			{
				//check categories of the node
				foreach($node->categories as $category)
				{
					//if node is in category defined in $outagesCategory
					$nodeCategoryName = (string) $category["name"];
					if(array_search($nodeCategoryName, $alarmsCategory) !== FALSE)
					{
						//add node to filter
						$nodeId = (string)$node["id"];
						$nodeFilter[] = $nodeId;
						continue 2;
					}
				}
			}
			
		}

		//create alarms array
		$alarmRecords = Array();
		foreach($alarmsXml->xpath('//alarm') as $alarm)
		{
			$alarmId = (string) $alarm['id'];
			$alarmSeverity = (string) $alarm['severity'];
			$alarmNodeId = 0;
			$alarmNodeLabel = "";
			if(isset($alarm->nodeId[0]))
			{
				$alarmNodeId = (string) $alarm->nodeId[0];
				$alarmNodeLabel = (string) $alarm->nodeLabel[0];
			}
			$alarmUei = (string) $alarm->uei[0];
			$alarmLogmessage = (string) $alarm->logMessage[0];
			$alarmTimestamp = strtotime((string) $alarm->lastEventTime[0]);

			//if ueiFilter is defined and alarm uei does not match the filter -> go to the next alarm
			if($ueiFilter != "" && preg_match("#$ueiFilter#", $alarmUei) !== 1)
			{
				continue;
			}

			//if nodeFilter is defined and node is not in filter -> go to the next alarm
			if(isset($nodeFilter) && array_search($alarmNodeId, $nodeFilter) === FALSE)
			{
				continue;
			}

			//if severityFilter is defined and alarm severity is not in filter -> go to the next alarm
			if(isset($severityFilter) && array_search($alarmSeverity, $severityFilter) === FALSE)
			{
				continue;
			}

			//save alarmRecord
			$alarmRecords[] = array("id" => $alarmId, "severity" => $alarmSeverity, "nodelabel" => $alarmNodeLabel, "uei" => $alarmUei, "log" => $alarmLogmessage, "timestamp" => $alarmTimestamp);
		}

		//generate output
		$output = "";
		$output .= "<h1 class=\"text-center\">$title</h1>";
		$output .= "<table class=\"dashboard-severity\">";
		$i = 0;
		//if there are no alarms
		if(count($alarmRecords) <= 0)
		{
			$output .= "<tr class=\"dashboard-severity-cleared\"><td>no alarms</td></tr>";
		}
		foreach($alarmRecords as $alarm)
		{
			//check, if output is too long
			if($maxEntries != "" && $i >= $maxEntries)
			{
				break;
			}

			//calculate outage interval
			$alarmInterval = time() - $alarm["timestamp"];

			//create alarm if configured for every alarm in the given interval
			if($createAlarms == "true" && $alarmInterval >= $alarmMinTime && $alarmInterval <= $alarmMaxTime )
			{
				echo "<script type=\"text/javascript\">addAlarm('onmsalarm-".$alarm["id"]."');</script>";
			}

			//define outageIntervalString
			$alarmIntervalString = "$alarmInterval sec";
			if($alarmInterval > 60)
			{
				$alarmIntervalString = round($alarmInterval / 60) . " min";
			}
			if($alarmInterval > 3600)
			{
				$alarmIntervalString = round($alarmInterval / 3600) . " h";
			}
			if($alarmInterval > 86400)
			{
				$alarmIntervalString = round($alarmInterval / 86400) . " d";
			}

			//get severity for output
			$outputSeverity = "major";
			switch($alarm['severity'])
			{
				case "CRITICAL":
					$outputSeverity = "critical";
					break;
				case "MAJOR":
					$outputSeverity = "major";
					break;
				case "MINOR":
					$outputSeverity = "minor";
					break;
				case "WARNING":
					$outputSeverity = "warning";
					break;
				case "INDETERMINATE":
					$outputSeverity = "warning";
					break;
				case "NORMAL":
					$outputSeverity = "info";
					break;
				case "CLEARED":
					$outputSeverity = "cleared";
					break;

			}

			//create output
			$output .= "<tr class=\"dashboard-severity-$outputSeverity\">";
			$output .= "<td class=\"dashboard-nowrap\"><a href=\"$linkUrlBase/alarm/detail.htm?id={$alarm['id']}\" target=\"_blank\">{$alarm['id']}</a></td>";
			$output .= "<td>{$alarm['nodelabel']}</td>";
			if($paramShowLogmessage != "false")
			{
				$output .= "<td>{$alarm['log']}</td>";
			}
			$output .= "<td class=\"dashboard-nowrap\">($alarmIntervalString)</td>";
			$output .= "</tr>";
			
			$i++;
		}


		//message, if output was too long
		if($maxEntries != "" && count($alarmRecords) > $maxEntries)
		{
			$countMissing = count($alarmRecords) - $maxEntries;
			$output .= "<tr class=\"dashboard-severity-major\"><td colspan=\"4\">$countMissing more alarms...</td></tr>";
		}

		$output  .= "</table>";

		//return output
		return $output;
	}

}
?>
