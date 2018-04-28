<!DOCTYPE html>
<html>
	<head>
		<title>List of RSS feeds available</title>
	</head>
	<body>
		<ul>
			<?php foreach ($sources as &$source):?>
				<li><a href="<?php echo htmlspecialchars($source->getClassName());?>.xml"><?php echo htmlspecialchars($source->getName());?></a></li>
			<?php endforeach;?>
		</ul>
	</body>
</html>