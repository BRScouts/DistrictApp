<?php
// Include your config.php file for database connection
include('config.php');

// Check if a store_id is provided
if (isset($_GET['store_id'])) {
    $store_id = intval($_GET['store_id']);

    // Fetch the group details from wp_posts
    $group_query = "SELECT post_title, post_content FROM wp0w_posts WHERE ID = $store_id AND post_type = 'wpsl_stores'";
    $group_result = $link->query($group_query);

    // Fetch metadata from wp_postmeta
    $meta_query = "SELECT meta_key, meta_value FROM wp0w_postmeta WHERE post_id = $store_id";
    $meta_result = $link->query($meta_query);

    if ($group_result->num_rows > 0) {
        $group = $group_result->fetch_assoc();
    } else {
        die("Group not found.");
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $new_title = $link->real_escape_string($_POST['post_title']);
        $new_content = $link->real_escape_string($_POST['post_content']);
        $new_meta = [];

        foreach ($_POST['meta'] as $key => $value) {
            $new_meta[$key] = $link->real_escape_string($value);
        }

        // Update wp_posts
        $update_post_query = "UPDATE wp0w_posts SET post_title = '$new_title', post_content = '$new_content' WHERE ID = $store_id";
        $link->query($update_post_query);

        // Update wp_postmeta
        foreach ($new_meta as $meta_key => $meta_value) {
            $update_meta_query = "UPDATE wp0w_postmeta SET meta_value = '$meta_value' WHERE post_id = $store_id AND meta_key = '$meta_key'";
            $link->query($update_meta_query);
        }

        echo "<p>Group details updated successfully!</p>";
    }
} else {
    die("No group ID provided.");
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Scout Group</title>
</head>
<body>
    <h1>Edit Scout Group: <?php echo htmlspecialchars($group['post_title']); ?></h1>
    <form method="POST">
        <label for="post_title">Group Name:</label><br>
        <input type="text" id="post_title" name="post_title" value="<?php echo htmlspecialchars($group['post_title']); ?>"><br><br>

        <label for="post_content">Group Description:</label><br>
        <textarea id="post_content" name="post_content" rows="5" cols="40"><?php echo htmlspecialchars($group['post_content']); ?></textarea><br><br>

        <h3>Metadata</h3>
        <?php
        while ($meta_row = $meta_result->fetch_assoc()) {
            if (in_array($meta_row['meta_key'], [
                'sub-header-subtitle', 
                'wpsl_group_contact', 
                'wpsl_group_website', 
                'wpsl_section_details', 
                'wpsl_group_link', 
                'wpsl_group_link2', 
                'wpsl_group_link3', 
                'wpsl_group_type',
                'wpsl_section_scarf',
                'wpsl_hours'
            ])) {
                echo '<label for="meta_' . $meta_row['meta_key'] . '">' . htmlspecialchars($meta_row['meta_key']) . ':</label><br>';
                echo '<input type="text" id="meta_' . $meta_row['meta_key'] . '" name="meta[' . $meta_row['meta_key'] . ']" value="' . htmlspecialchars($meta_row['meta_value']) . '"><br><br>';
            }
        }
        ?>

        <input type="submit" value="Save Changes">
    </form>
</body>
</html>

<?php
$link->close();
?>
