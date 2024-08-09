<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01//EN" "http://www.w3.org/TR/html4/strict.dtd">
<html>
<head>
<meta http-equiv="content-type" content="text/html; charset=utf-8">
<title>TN county selection</title>
<meta name="author" content="Marcel Quirijnen" />
<meta name="company" content="Little Sugar Creek Technology Services, Inc." />
<link rel="stylesheet" type="text/css" href="/css/main.css" />
<script type="text/javascript" src="/js/jquery.js"></script>
<script type="text/javascript">

	function lookup(inputString) {
		if (inputString.length == 0) {
			// Hide the suggestion box.
			$('#suggestions').hide();
		} else {
			$.post("get_counties.php", {queryString: ""+inputString+""}, function(data){
				if(data.length >0) {
					$('#suggestions').show();
					$('#autoSuggestionsList').html(data);
				}
			});
		}
	}
	
	function fill(thisValue, thisOtherValue) {
		$('#county').val(thisValue);
		$('#geo').val(thisOtherValue);
		setTimeout("$('#suggestions').hide();", 200);
	}

</script>

<style type="text/css">
.suggestionsBox {
		position: relative;
		left: 30px;
		margin: 10px 0px 0px 0px;
		width: 200px;
		background-color: #547DA5;
		-moz-border-radius: 7px;
		-webkit-border-radius: 7px;
		border: 2px solid #000;	
		color: #fff;
}
	
.suggestionList {
		margin: 0px;
		padding: 0px;
}
	
.suggestionList li {		
		margin: 0px 0px 3px 0px;
		padding: 3px;
		cursor: pointer;
}
	
.suggestionList li:hover {
		background-color: #659CD8;
}

.DRquestion {
   font-weight: bold;
}
</style>

</head>
	<div>
		<form name="jumpform" id="jumpform" method="post" action="TNcounties.php">
			<div>
				<div class="DRquestion">What county are you looking for ?</div>
				I will suggest matches, once you start typing
				<br />
				<div class="drmessage"><input type="text" size="30" value="" id="county" onkeyup="lookup(this.value);" onBlur="fill();"/></div>
				<input type="hidden" id="geo" name="geo" value=""> 
			</div>
			
			<div class="suggestionsBox" id="suggestions" style="display: none;">
				<img src="/images/upArrow.png" style="position: relative; top: -17px; left: 30px;" alt="upArrow" />
				<div class="suggestionList" id="autoSuggestionsList">
					&nbsp;
				</div>
			</div>
			
			<br />
			<input class="primaryAction" name="action" value="Show Me" type="submit" />
		</form>
	</div>
</body>
</html>

