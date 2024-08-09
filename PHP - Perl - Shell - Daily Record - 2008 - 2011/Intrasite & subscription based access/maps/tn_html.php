<?
//include_once './include/chklogin.php';
include_once 'siteconfig.php';
//include_once './head.php';

?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en" xml:lang="en">
<head>
<title>TN Counties Newspapers Deadline Management</title>
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
   <div id="title">.:: TN Counties Newspapers Deadline Management ::.</div>
</div>
	
<div id="gridbox" style="width:1300px; height:700px; background-color:white;margin-left: 25px"></div>
	
<div style="display:none">
   <div id="county_flt_box"><input type="text" style="width: 100%; border:1px solid gray;" onClick="(arguments[0]||window.event).cancelBubble=true;" onKeyUp="filterBy()"></div>
   <div id="geo_flt_box"><select style="width:100%" onclick="(arguments[0]||window.event).cancelBubble=true;" onChange="filterBy()"></select></div>
</div>

<script>
		function doOnLoad(){
			mygrid = new dhtmlXGridObject('gridbox');
			mygrid.setImagePath("/dhtmlxSuite/dhtmlxGrid/codebase/imgs/");
			mygrid.setColumnIds("newspaper,county,geo,seat,pubdates,deadline,pops,door,officer,tz,memorial_day_info,independence_day_info,labor_day_info,thanksgiving_day_info,veterans_day_info,xmas_day_info,newyears_day_info");
		    mygrid.setHeader("Newspaper,County,Geo,Seat,Pub Dates,Deadlines,Pops,Door,Officer,Timezone,Memorial Day,Independence Day,Labor Day,Thanksgiving,Veterans Day,Christmas, New Years Day");
			mygrid.setInitWidths("150,120,100,100,170,200,50,100,120,100,200,200,200,200,200,200,200");
			mygrid.setColAlign("left,left,center,left,left,left,center,center,center,center,left,left,left,left,left,left,left");
			mygrid.setColTypes("txt,edtxt,coro,edtxt,txt,txt,edtxt,edtxt,edtxt,coro,txt,txt,txt,txt,txt,txt,txt");
		    mygrid.getCombo(2).put('east', 'East');
		    mygrid.getCombo(2).put('middle', 'Middle');
		    mygrid.getCombo(2).put('west', 'West');
		    mygrid.getCombo(9).put('CST', 'CST');
		    mygrid.getCombo(9).put('EST', 'EST');
			mygrid.setColSorting("str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str,str");
			mygrid.setColumnColor("white,#d5f1ff,#d5f1ff,white,white,white,white,white,white,white,white,white,white,white,white,white,white");
		    mygrid.setColumnMinWidth(50,0);
			mygrid.setSkin("dhx_skyblue");
			mygrid.init();
			mygrid.loadXML("tn_xml.php?action=select", function() {
				mygrid.attachHeader("#rspan,<div id='county_flt' style='padding-right:3px'></div>,<div id='geo_flt' style='padding-right:3px'></div>,#rspan,#rspan,#rspan,#rspan,#rspan");
				//set county filter field
				document.getElementById("county_flt").appendChild(document.getElementById("county_flt_box").childNodes[0]);
				//set geo filter field
				var geoFlt = document.getElementById("geo_flt").appendChild(document.getElementById("geo_flt_box").childNodes[0]);
				populateSelectWithGeo(geoFlt);
				//block sorting and resize actions for second row
				mygrid.hdr.rows[2].onmousedown=mygrid.hdr.rows[2].onclick=function(e){(e||event).cancelBubble=true;}
				mygrid.setSizes();
			});
            
            function not_empty(value,id,ind) {
               if (value == "") 
                  mygrid.setCellTextStyle(id,ind,"background-color:red;");
		       return value != "";
	        }
            
            myDataProcessor = new dataProcessor("tn_xml.php?action=update"); 
            myDataProcessor.setVerificator(1, not_empty);  //county
            myDataProcessor.setVerificator(2, not_empty);  //geo
            myDataProcessor.setVerificator(3, not_empty);  //seat
            myDataProcessor.setVerificator(4, not_empty);  //pub dates
            myDataProcessor.setVerificator(5, not_empty);  //deadlines
            myDataProcessor.setVerificator(9, not_empty);  //tz
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
			var aVal = document.getElementById("geo_flt").childNodes[0].value.toLowerCase();
			
			for(var i = 0; i < mygrid.getRowsNum(); i++) {
				var tStr = mygrid.cells2(i,1).getValue().toString().toLowerCase();
				var aStr = mygrid.cells2(i,2).getValue().toString().toLowerCase();
				if ((tVal == "" || tStr.indexOf(tVal) == 0) && (aVal == "" || aStr.indexOf(aVal) == 0))
					mygrid.setRowHidden(mygrid.getRowId(i),false);
				else
					mygrid.setRowHidden(mygrid.getRowId(i),true);
			}
		}
		
		function populateSelectWithGeo(selObj) {
			selObj.options.add(new Option("All Geo",""))
			var usedGeoAr = new dhtmlxArray();
			for (var i = 0; i < mygrid.getRowsNum(); i++) {
				var geoNm = mygrid.cells2(i,2).getValue();
				if (usedGeoAr._dhx_find(geoNm) == -1) {
					selObj.options.add(new Option(geoNm,geoNm));
					usedGeoAr[usedGeoAr.length] = geoNm;
				}
			}
		}
		
</script>

</body>
</html>
