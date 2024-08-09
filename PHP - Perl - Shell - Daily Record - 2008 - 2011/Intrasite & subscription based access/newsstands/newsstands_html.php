<?
//include_once $_SERVER['DOCUMENT_ROOT'] . 'include/chklogin.php';
include_once 'siteconfig.php';
//include_once $_SERVER['DOCUMENT_ROOT'] . 'head.php';

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
<title>Newsstands and Racks Management</title>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
<meta name="author" content="Marcel Quirijnen">
<meta name="company" content="Little Sugar Creek Technology Services, Inc.">
</head>
<body class="yui-skin-sam" onLoad="doOnLoad();">
<!-- -->
<!-- http://docs.dhtmlx.com/doku.php?id=dhtmlxgrid:base_types -->
<!-- -->

<link rel="stylesheet" type="text/css" href="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxgrid.css">
<link rel="stylesheet" type="text/css" href="/dhtmlxSuite/dhtmlxGrid/codebase/skins/dhtmlxgrid_dhx_skyblue.css">

<script  src="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxcommon.js"></script>
<script  src="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxgrid.js"></script>        
<script  src="/dhtmlxSuite/dhtmlxGrid/codebase/dhtmlxgridcell.js"></script>
<script  src="/dhtmlxSuite/dhtmlxDataProcessor/codebase/dhtmlxdataprocessor.js"></script>

<link href="/css/main.css" type="text/css" rel="stylesheet" />
<br />
<div align="center" class="DRmessage">
   <div id="title">.:: Newsstands and Racks Management ::.</div>
</div>
	
<div id="gridbox" style="width:1230px; height:600px; background-color:white;margin-left: 25px"></div>
	
<div style="display:none">
   <div id="county_flt_box"><select style="width:100%" onclick="(arguments[0]||window.event).cancelBubble=true;" onChange="filterBy()"></select></div>
</div>

<script>
		function doOnLoad(){
			mygrid = new dhtmlXGridObject('gridbox');
			mygrid.setImagePath("/dhtmlxSuite/dhtmlxGrid/codebase/imgs/");
			mygrid.setColumnIds("county,location,address,city,state,zip,contact,latitude,longitude");
		    mygrid.setHeader("County,Location,Address,City,State,Zip,Contact,Latitude,Longitude");
			mygrid.setInitWidths("150,200,200,100,50,70,250,100,100");
			mygrid.setColAlign("center,center,center,center,center,center,center,center,center");
			mygrid.setColTypes("coro,edtxt,txt,edtxt,coro,edtxt,txt,edtxt,edtxt");
		    mygrid.getCombo(0).put('Pulaski County', 'Pulaski County');
		    mygrid.getCombo(0).put('Lonoke County', 'Lonoke County');
		    mygrid.getCombo(0).put('Saline County', 'Saline County');
		    mygrid.getCombo(0).put('Faulkner County', 'Faulkner County');
		    mygrid.getCombo(4).put('AR', 'AR');
			mygrid.setColSorting("str,str,str,str,str,str,str,str,str");
			mygrid.setColumnColor("white,white,white,white,white,white,white,white,white");
		    mygrid.setColumnMinWidth(50,0);
			mygrid.setSkin("dhx_skyblue");
			mygrid.init();
			mygrid.loadXML("newsstands_xml.php?action=select", function() {
				mygrid.attachHeader("<div id='county_flt' style='padding-right:3px'></div>,#rspan,#rspan,#rspan,#rspan,#rspan,#rspan,#rspan,#rspan");
				//set county filter field
				var CountyFlt = document.getElementById("county_flt").appendChild(document.getElementById("county_flt_box").childNodes[0]);
				populateSelectWithCounties(CountyFlt);
				//block sorting and resize actions for second row
				mygrid.hdr.rows[2].onmousedown=mygrid.hdr.rows[2].onclick=function(e){(e||event).cancelBubble=true;}
				mygrid.setSizes();
			});
            
            function not_empty(value,id,ind) {
               if (value == "") 
                  mygrid.setCellTextStyle(id,ind,"background-color:red;");
		       return value != "";
	        }
            
            myDataProcessor = new dataProcessor("newsstands_xml.php?action=update"); 
            myDataProcessor.setVerificator(0, not_empty);  //county
            myDataProcessor.setVerificator(1, not_empty);  //location
            myDataProcessor.setVerificator(2, not_empty);  //address
            myDataProcessor.setVerificator(3, not_empty);  //city
            myDataProcessor.setVerificator(4, not_empty);  //state
            myDataProcessor.setVerificator(5, not_empty);  //zip
            myDataProcessor.setVerificator(7, not_empty);  //latitude
            myDataProcessor.setVerificator(8, not_empty);  //longitude
            myDataProcessor.attachEvent("onRowMark",function(id) {
		       if (this.is_invalid(id) == "invalid") 
		          return false;
		       return true;
	        });
	        myDataProcessor.enableDataNames(true);     //will use names instead of indexes
	        myDataProcessor.init(mygrid);              //link dataprocessor to the grid
		}
		
		function filterBy(){
			var tVal = document.getElementById("county_flt").childNodes[0].value.toLowerCase();
			
			for(var i = 0; i < mygrid.getRowsNum(); i++) {
				var tStr = mygrid.cells2(i,0).getValue().toString().toLowerCase();
				if ( tVal == "" || tStr.indexOf(tVal) == 0 )
					mygrid.setRowHidden(mygrid.getRowId(i),false);
				else
					mygrid.setRowHidden(mygrid.getRowId(i),true);
			}
		}
		
		function populateSelectWithCounties(selObj) {
			selObj.options.add(new Option("All counties",""))
			var usedCountiesAr = new dhtmlxArray();
			for (var i = 0; i < mygrid.getRowsNum(); i++) {
				var countiesNm = mygrid.cells2(i,0).getValue();
				if (usedCountiesAr._dhx_find(countiesNm) == -1) {
					selObj.options.add(new Option(countiesNm,countiesNm));
					usedCountiesAr[usedCountiesAr.length] = countiesNm;
				}
			}
		}
		
</script>

<div align="left" class="DRmessage">
   &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="help.html">How to obtain Latitude / Longitude info</a>
</div>


</body>
</html>