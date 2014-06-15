<?php
/********************************************************************
* This file is part of yourDashboard.
*
* Copyright 2014 Michael Batz
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

/**
* connector to OTRS
* @author: Michael Batz <michael@yourcmdb.org>
*/
class ConnectorOtrs
{

	//base URL for the OTRS SOAP API
	private $soapUrl;

	//user for the OTRS SOAP API
	private $soapUser;

	//password for the OTRS SOAP API
	private $soapPassword;

	//soap client
	private $soapClient;

	function __construct($soapUrl, $soapUser, $soapPassword)
	{
		$this->soapUrl = $soapUrl;
		$this->soapUser = $soapUser;
		$this->soapPassword = $soapPassword;

		//create soapClient with user credentials
		$soapOptions = Array(	"location" 	=> $soapUrl, 
					"uri" 		=> "urn:otrs-com:soap:functions");
		$this->soapClient = new SoapClient(null, $soapOptions);
	}


	/**
	* Gets tickets from OTRS
	* @param $queue name of the queue to get tickets
	* @param $limit max count of output entries
	* @return array with ticketIDs
	*/
	public function getTickets($queue, $limit)
	{
		//soap call to get all new or open tickets of queue $queue
		$soapMessage = Array();
		$soapMessage[] = new SoapParam($this->soapUser, "UserLogin");
		$soapMessage[] = new SoapParam($this->soapPassword, "Password");
		$soapMessage[] = new SoapParam("ARRAY", "Result");
		$soapMessage[] = new SoapParam($limit, "Limit");
		$soapMessage[] = new SoapParam($queue, "Queues");
		$soapMessage[] = new SoapParam("new", "States");
		$soapMessage[] = new SoapParam("open", "States");
		$soapMessage[] = new SoapParam("Down", "OrderBy");
		$soapMessage[] = new SoapParam("Age", "SortBy");
		//returns a single ticketId or an array of ticketIds, if multiple tickets were found
		$tickets = $this->soapClient->__soapCall("TicketSearch", $soapMessage);

		//check if ticket is array
		if(is_array($tickets))
		{
			return $tickets['TicketID'];
		}
		elseif($tickets != null)
		{
			return array($tickets);
		}
		else
		{
			return array();
		}
	}

	public function getTicketSummary($ticketId)
	{
		//soap call: get ticket
		$soapMessage = Array();
		$soapMessage[] = new SoapParam($this->soapUser, "UserLogin");
		$soapMessage[] = new SoapParam($this->soapPassword, "Password");
		$soapMessage[] = new SoapParam($ticketId, "TicketID");
		$ticket = $this->soapClient->__soapCall("TicketGet", $soapMessage);

		//generate output array
		$output = Array();
		$output['TicketNumber'] = $ticket->TicketNumber;
		$output['Title'] = $ticket->Title;
		$output['TicketID'] = $ticket->TicketID;
		$output['Age'] = $ticket->Age;
		return $output;
	}
}
?>
