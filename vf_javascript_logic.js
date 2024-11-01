/* The URL to request the ticket state */
verifyneTicketStateURL = "https://api.verifyne.me/v1/ticket-state?ticket-id="+encodeURIComponent(verifyneTicketID);
/* Where to go after a ticket has beeen authenticated */
// verifyneRedirectForVerification = (printed above)
/* Where to go after a ticket has expired */
// verifyneRedirectForNewTicket    = (printed above)

stopPoll=false;

function verifyneUpdateStateInfo(data) {
	switch(data.result.code)
	{
	/* OK */        
	case 0:
		stopPoll=true;
		jQuery("#verifyne-state-div").html("Redirecting for verification ...");
		location.replace(verifyneRedirectForVerification);
		break;
	/* Ticket not found */
	case 2:
		stopPoll=true;
		jQuery("#verifyne-state-div").html("Ticket not found");
		break;
	/* Ticket expired */
	case 3:
		stopPoll=true;
		jQuery("#verifyne-state-div").html("Tiket expired. Redirecting ...");
		location.replace(verifyneRedirectForNewTicket);
		break;
	/* Ticket not assigned */
	case 4:
		jQuery("#verifyne-state-div").html("Please scan the QR code");
		break;
	/* Error */
	case 1:
	default:
		stopPoll=true;
		jQuery("#verifyne-state-div").html("API error");
	}                            
}

function verifynePoll(){
	if(stopPoll) { return; }
	verifyne_poll = jQuery.ajax({
		url:      verifyneTicketStateURL,
		success:  verifyneUpdateStateInfo,
		complete: verifynePoll,
		timeout:  10000,
		dataType: "json"
	});
};

verifynePoll();
