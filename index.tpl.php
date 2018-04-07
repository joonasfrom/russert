<!DOCTYPE html>
<html>
	<head>
	</head>
	<body>
		<ul>
			<?php foreach ($source_filenames as $filename):?>
				<li><a href="<?php echo $filename;?>"><?php echo $filename;?></a></li>
			<?php endforeach;?>
		</ul>
	</body>
</html>