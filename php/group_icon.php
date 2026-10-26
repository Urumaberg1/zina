<?php
/**
 * Stream a group icon only to current group members.
 */
require_once __DIR__ . '/config.php';
startAppSession();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit;
}
$userId = requireAuthenticatedUser();
$groupId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$groupId) {
    http_response_code(400);
    exit;
}
$conn = initDatabase();
$stmt = $conn->prepare("
    SELECT g.icon_path
    FROM chat_groups g
    JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
    WHERE g.id = ?
");
$stmt->execute([$userId, $groupId]);
$iconPath = $stmt->fetchColumn();
if (!$iconPath) {
    http_response_code(404);
    exit;
}
$directory = realpath(__DIR__ . '/uploads/group-icons');
$filePath = realpath(__DIR__ . '/uploads/group-icons/' . basename($iconPath));
if (!$directory || !$filePath || strpos($filePath, $directory . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    exit;
}
$mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
    http_response_code(415);
    exit;
}
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($filePath);
?>
