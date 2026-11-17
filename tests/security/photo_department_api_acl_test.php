<?php
require_once __DIR__ . '/../../api/v1/core/acl.php';

function expectPhotoAcl(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$photo = ['rol' => 'photo_dept'];
$legacyPhoto = ['rol' => 'photo_importer'];
$unknown = ['rol' => 'unrecognized_role'];

foreach ([$photo, $legacyPhoto] as $auth) {
    expectPhotoAcl(apiCanBrowseMembers($auth), 'Photo roles must browse the member directory.');
    expectPhotoAcl(apiCanUploadMemberPhotos($auth), 'Photo roles must upload photos.');
    expectPhotoAcl(!apiCanManageMembers($auth), 'Photo roles must not edit general member data.');
    expectPhotoAcl(!apiRoleIs($auth, apiRolesMobileDashboard()), 'Photo roles must not receive dashboard data.');
    expectPhotoAcl(!apiRoleIs($auth, apiRolesClassBrowser()), 'Photo roles must not receive class data.');
}

expectPhotoAcl(!apiCanBrowseMembers($unknown), 'Unknown roles must not browse members.');
expectPhotoAcl(!apiCanUploadMemberPhotos($unknown), 'Unknown roles must not upload photos.');

$row = apiMemberPhotoRow([
    'id' => 17,
    'member_code' => 'M-17',
    'student_name' => 'Member',
    'father_name' => 'Parent',
    'grandfather_name' => 'Grandparent',
    'phone_number' => 'private',
    'gender' => 'private',
    'current_section' => 'private',
    'status' => 'private',
]);
expectPhotoAcl(
    array_keys($row) === ['id', 'member_code', 'student_name', 'father_name', 'grandfather_name', 'photo_url'],
    'Photo member projection must exclude unrelated personal data.'
);

echo "Photo department API ACL tests passed.\n";
