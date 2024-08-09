<table class="table">
	<thead>
	<tr>
		<th>Date</th>
		<th>Patch Level</th>
		<th>Client</th>
		<th>Appliance</th>
		<th>Group</th>
		<th>Indicator</th>
		<th>Status</th>
		<th>Results</th>
		<th>Remediation Attempts</th>
		<th>Remediation Details</th>
	</tr>
	</thead>
	<tbody>
	<?php foreach($details as $detail): ?>
		<tr>
			<td style="white-space: nowrap;"><?php echo $detail['time'] ?></td>
			<td><?php echo $detail['patch_level'] ?></td>
			<td><?php echo $detail['client'] ?></td>
			<td><?php echo $detail['appliance'] ?></td>
			<td><?php echo $detail['group'] ?></td>
			<td><?php echo $detail['indicator'] ?></td>
			<td><?php echo $detail['status'] ?></td>
			<td><?php echo $detail['notes'] ?></td>
			<td><?php echo $detail['remediation_attempts'] ?></td>
			<td><?php echo $detail['remediation_details'] ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>