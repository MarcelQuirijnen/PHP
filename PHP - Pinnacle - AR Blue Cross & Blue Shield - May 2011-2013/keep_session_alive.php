<?php
/*
 * If opened in a separate tab in FireFox and leave running, this will keep your POWER session from expiring
 */

	require (dirname(__FILE__)."/../includes/menuhead.inc"); 
?><script language="JavaScript">
var sURL = unescape(window.location.pathname);
function doLoad() {
    setTimeout( "refresh()", 10*60*1000 );
}
function refresh() {
    window.location.href = sURL;
}
doLoad();
</script>
	<h2 align=center>Keep this page open, it will keep your POWER session from expiring</h2>
<?php

	require (dirname(__FILE__)."/../includes/tail.inc");
	exit;
?>