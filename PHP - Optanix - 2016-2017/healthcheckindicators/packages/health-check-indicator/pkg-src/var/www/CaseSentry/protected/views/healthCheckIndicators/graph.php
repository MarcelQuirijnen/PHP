<div class="header">
	<div class="row">
		<div class="col-sm-12">
			<ul class="breadcrumb">
				<li><a href="<?php echo $this->createUrl('index') ?>">HealthCheck Indicators</a></li>
				<li class="active">HCI Failures vs Auto-Remediation</li>
			</ul>
		</div>
	</div>
</div>

<div class="panel-body">
	<div class="row">
		<div class="col-sm-12">
			<div id="graph"></div>
		</div>
	</div>
	<div class="row">
		<div class="col-sm-12">
			<br>
			<div class="panel-divider"></div>
			<form action="<?php echo $this->createUrl('export') ?>" id="export-form" method="post"></form>
			<form class="form" method="post">
				<div class="col-sm-6" style="height: 34px; line-height: 34px;">
					<div id="error" class="text-danger"></div>
				</div>
				<div class="col-sm-2">
					<input type="text" id="start-date" name="start-date" class="form-control col-sm-2" placeholder="Start Date">
				</div>
				<div class="col-sm-2">
					<input type="text" id="end-date" name="end-date" class="form-control col-sm-2" placeholder="End Date">
				</div>
				<div class="col-sm-2">
					<button id="run" type="submit" class="btn btn-primary">Run</button>
					<a href="#" class="btn btn-default" id="export-btn" onclick="$('#export-form').submit(); return false;">
						<span class="glyphicon glyphicon-download-alt"></span> Export
					</a>
				</div>
			</form>
			<br>
		</div>
	</div>
	<div class="row">
		<div class="col-sm-12">
			<br>
			<div class="panel-divider"></div>
			<br>
			<div id="details">
				<?php if(isset($data['details'])) { ?>
					<?php echo $data['details'] ?>
				<?php } ?>
			</div>
		</div>
	</div>
</div>

<script src="<?php echo Yii::app()->baseUrl ?>/js/highcharts.js"></script>

<script type="text/javascript">
	$(function () {

		$('#start-date').datetimepicker({
			format: "Y-m-d H:i",
			closeOnDateSelect:true
		});

		$('#end-date').datetimepicker({
			format: "Y-m-d H:i",
			closeOnDateSelect:true
		});

		$('#run').on('click', function(e) {
			e.preventDefault();
			$('#run').html('<span class="loader loader-sm">Loading...</span>');
			var startDate = $('#start-date').val();
			var endDate = $('#end-date').val();

			if(startDate.length == 0 || endDate.length == 0) {
				$('#error').html('Start Date and End Date are required.');
				$('#run').html('Run');
				return false;
			}
			else {
				$('#error').html('');
			}

			$.ajax({
				url: '<?php echo Yii::app()->createUrl('healthCheckIndicators/generateDetailsByDateRange/') ?>',
				data: {
					startDate: startDate,
					endDate: endDate
				},
				dateType: 'json',
				type: 'POST',
				beforeSend: function() {
					$('#details').html('<div class="no-results">Loading<br/><div class="loader loader-sm"></div></div>');
				},
				success: function(response) {
					if(response.status == 'fail') {
						$('#error').html(response.message);
						$('#details').html('');
					}
					else {
						$('#details').html(response.message);
					}
				},
				complete: function() {
					$('#run').html('Run');
				}
			});
		});

		Highcharts.setOptions({
			global: {
				useUTC: false
			}
		});

		new Highcharts.Chart({
			chart: {
				renderTo: 'graph',
				zoomType: 'x'
			},
			credits: {
				enabled: false
			},
			legend: {
				align: 'right',
				verticalAlign: 'bottom',
				layout: 'vertical',
				floating: true,
				x: -40,
				y: -30,
				backgroundColor: '#ffffff'
			},
			title: {
				text: null
			},
			subtitle: {
				text: null
			},
			xAxis: {
				type: 'datetime',
				dateTimeLabelFormats: {
					month: '%e %b',
					year: '%b'
				},
				tickInterval: 24 * 3600 * 1000
			},
			yAxis: {
				title: {
					text: null
				},
				min: 0
			},
			symbols: ['circle', 'circle'],
			colors: ['#f45b5b', '#569c22'],
			plotOptions: {
				series: {
					animation: false,
					fillOpacity: 0.4,
					marker: {
						lineWidth: 1
					},
				}
			},
			series: [
				{
					name: 'Failures', type: 'areaspline',  data: [
					<?php echo $data['failing'] ?>
				],
				},
				{
					name: 'Auto-Remediations', type: 'areaspline', data: [
					<?php echo $data['remediating'] ?>
				]
				}
			]
		});

	});
</script>
