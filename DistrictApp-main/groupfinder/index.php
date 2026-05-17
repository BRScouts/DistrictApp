<?php
// Include database config
include('config.php');

// Define constants
define("SFL_WPSL_SECTIONS", array('Squirrels', 'Beavers', 'Cubs', 'Scouts', 'Explorers', 'Network', 'SASU'));
define("SFL_WPSL_DAYS", array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'));
define("SFL_WPSL_HOURS", array('12:00 AM', '12:15 AM', '12:30 AM', '12:45 AM', '1:00 AM', '1:15 AM', '1:30 AM', '1:45 AM', '2:00 AM', '2:15 AM', '2:30 AM', '2:45 AM', '3:00 AM', '3:15 AM', '3:30 AM', '3:45 AM', /* continue up to '11:45 PM'*/ ));

// Fetch Scout Groups from wp_posts
function get_scout_groups($link) {
    $query = "SELECT ID, post_title FROM wp0w_posts WHERE post_type = 'wpsl_stores' AND post_status = 'publish'";
    return $link->query($query);
}

// Fetch Scout Group details including metadata
function get_scout_group_details($link, $store_id) {
    $post_query = "SELECT post_title, post_content FROM wp0w_posts WHERE ID = $store_id";
    $meta_query = "SELECT meta_key, meta_value FROM wp0w_postmeta WHERE post_id = $store_id";
    return [
        'post' => $link->query($post_query)->fetch_assoc(),
        'meta' => $link->query($meta_query)->fetch_all(MYSQLI_ASSOC)
    ];
}

// Update Scout Group details
function update_scout_group($link, $store_id, $post_title, $post_content, $meta_data) {
    // Update wp_posts
    $update_post_query = "UPDATE wp0w_posts SET post_title = '$post_title', post_content = '$post_content' WHERE ID = $store_id";
    $link->query($update_post_query);

    // Update wp_postmeta
    foreach ($meta_data as $meta_key => $meta_value) {
        $meta_value = $link->real_escape_string($meta_value);
        $update_meta_query = "UPDATE wp0w_postmeta SET meta_value = '$meta_value' WHERE post_id = $store_id AND meta_key = '$meta_key'";
        $link->query($update_meta_query);
    }
}

// Helper function to convert section codes to readable names
function get_section_name($code) {
    $sections = array('Squirrels', 'Beavers', 'Cubs', 'Scouts', 'Explorers', 'Network', 'SASU');
    return isset($sections[$code]) ? $sections[$code] : 'Unknown';
}

// If form is submitted, update Scout Group details
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $store_id = intval($_POST['store_id']);
    $post_title = $link->real_escape_string($_POST['post_title']);
    $post_content = $link->real_escape_string($_POST['post_content']);
    $meta_data = $_POST['meta'];

    // Process section details (convert human-readable time back to index)
    $section_details = [];
    foreach ($_POST['section'] as $section) {
        $section_details[] = [
            'day' => intval($section['day']),
            'type' => intval($section['type']),
            'time_start' => array_search($section['time_start'], SFL_WPSL_HOURS),
            'time_finish' => array_search($section['time_finish'], SFL_WPSL_HOURS),
            'name' => $section['name'] ?? ''
        ];
    }
    $meta_data['wpsl_section_details'] = json_encode($section_details);

    // Call the update function
    update_scout_group($link, $store_id, $post_title, $post_content, $meta_data);
    echo "<p>Group details updated successfully!</p>";
}

// Fetch details for editing if a Scout Group is selected
if (isset($_GET['store_id'])) {
    $store_id = intval($_GET['store_id']);
    $group = get_scout_group_details($link, $store_id);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Scout Groups</title>
</head>
<body>

<h1>Select Scout Group to Edit</h1>

<?php if (!isset($group)): ?>
    <ul>
        <?php
        // List Scout Groups
        $scout_groups = get_scout_groups($link);
        while ($row = $scout_groups->fetch_assoc()) {
            echo "<li><a href='?store_id=" . $row['ID'] . "'>" . $row['post_title'] . "</a></li>";
        }
        ?>
    </ul>
<?php else: ?>
    <!-- Edit form -->
    <h2>Edit Group: <?php echo htmlspecialchars($group['post']['post_title']); ?></h2>
    <form method="POST">
        <input type="hidden" name="store_id" value="<?php echo $store_id; ?>">

        <label for="post_title">Group Name:</label><br>
        <input type="text" id="post_title" name="post_title" value="<?php echo htmlspecialchars($group['post']['post_title']); ?>"><br><br>

        <label for="post_content">Group Description:</label><br>
        <textarea id="post_content" name="post_content" rows="5" cols="40"><?php echo htmlspecialchars($group['post']['post_content']); ?></textarea><br><br>

        <label for="meta[wpsl_email]">Contact Email:</label><br>
        <input type="email" id="meta[wpsl_email]" name="meta[wpsl_email]" value="<?php echo htmlspecialchars($group['meta'][array_search('wpsl_email', array_column($group['meta'], 'meta_key'))]['meta_value']); ?>"><br><br>

        <label for="meta[wpsl_group_website]">Website:</label><br>
        <input type="url" id="meta[wpsl_group_website]" name="meta[wpsl_group_website]" value="<?php echo htmlspecialchars($group['meta'][array_search('wpsl_group_website', array_column($group['meta'], 'meta_key'))]['meta_value']); ?>"><br><br>

        <h3>Sections</h3>
        <div id="sections">
            <?php
            // Show sections as editable fields
            $section_details = json_decode($group['meta'][array_search('wpsl_section_details', array_column($group['meta'], 'meta_key'))]['meta_value'], true);
            foreach ($section_details as $index => $section) {
                ?>
                <div class="section">
                    <label>Section:</label>
                    <select name="section[<?php echo $index; ?>][type]">
                        <?php foreach (SFL_WPSL_SECTIONS as $key => $section_name): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($section['type'] == $key) ? 'selected' : ''; ?>><?php echo $section_name; ?></option>
                        <?php endforeach; ?>
                    </select><br>

                    <label>Day:</label>
                    <select name="section[<?php echo $index; ?>][day]">
                        <?php foreach (SFL_WPSL_DAYS as $key => $day_name): ?>
                            <option value="<?php echo $key; ?>" <?php echo ($section['day'] == $key) ? 'selected' : ''; ?>><?php echo $day_name; ?></option>
                        <?php endforeach; ?>
                    </select><br>

                    <label>Time Start:</label>
                    <select name="section[<?php echo $index; ?>][time_start]">
                        <?php foreach (SFL_WPSL_HOURS as $time): ?>
                            <option value="<?php echo $time; ?>" <?php echo ($time == SFL_WPSL_HOURS[$section['time_start']]) ? 'selected' : ''; ?>><?php echo $time; ?></option>
                        <?php endforeach; ?>
                    </select><br>

                    <label>Time Finish:</label>
                    <select name="section[<?php echo $index; ?>][time_finish]">
                        <?php foreach (SFL_WPSL_HOURS as $time): ?>
                            <option value="<?php echo $time; ?>" <?php echo ($time == SFL_WPSL_HOURS[$section['time_finish']]) ? 'selected' : ''; ?>><?php echo $time; ?></option>
                        <?php endforeach; ?>
                    </select><br><br>
                </div>
            <?php } ?>
        </div>

        <button type="button" onclick="addSection()">Add Section</button><br><br>

        <input type="submit" value="Save Changes">
    </form>

    <script>
        function addSection() {
            const sections = document.getElementById('sections');
            const newIndex = sections.children.length;
            const newSection = `
                <div class="section">
                    <label>Section:</label>
                    <select name="section[${newIndex}][type]">
                        <?php foreach (SFL_WPSL_SECTIONS as $key => $section_name): ?>
                            <option value="<?php echo $key; ?>"><?php echo $section_name; ?></option>
                        <?php endforeach; ?>
                    </select><br>

                    <label>Day:</label>
                    <select name="section[${newIndex}][day]">
                        <?php foreach (SFL_WPSL_DAYS as $key => $day_name): ?>
                            <option value="<?php echo $key; ?>"><?php echo $day_name; ?></option>
                        <?php endforeach; ?>
                    </select><br>

                    <label>Time Start:</label>
                    <select name="section[${newIndex}][time_start]">
                        <?php foreach (SFL_WPSL_HOURS as $time): ?>
                            <option value="${time}">${time}</option>
                        <?php endforeach; ?>
                    </select><br>

                    <label>Time Finish:</label>
                    <select name="section[${newIndex}][time_finish]">
                        <?php foreach (SFL_WPSL_HOURS as $time): ?>
                            <option value="${time}">${time}</option>
                        <?php endforeach; ?>
                    </select><br><br>
                </div>`;
            sections.insertAdjacentHTML('beforeend', newSection);
        }
    </script>
<?php endif; ?>

</body>
</html>

<?php
// Close database connection
$link->close();
?>
