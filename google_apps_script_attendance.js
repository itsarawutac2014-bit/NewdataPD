/**
 * =========================================================================
 * Google Apps Script: ระบบเชื่อมโยง ระเบียนพนักงาน & บันทึกการทำงาน ฝ่ายผลิต PD
 * =========================================================================
 * 
 * วิธีการติดตั้งลงใน Google Sheet ของคุณ:
 * 1. เปิดไฟล์ Google Sheet ของคุณที่ต้องการใช้
 * 2. ไปที่เมนู "ส่วนขยาย" (Extensions) > "Apps Script"
 * 3. ลบโค้ดเดิมทั้งหมดในหน้าต่าง แล้ววางโค้ดชุดนี้ลงไปทั้งหมด
 * 4. กดปุ่ม "บันทึก" (รูปแผ่นดิสก์)
 * 5. เลือกรันฟังก์ชัน "setupSheetAndSeedEmployees" แล้วกดปุ่ม "เรียกใช้" (Run)
 *    -> ระบบจะสร้างแท็บ "Employees" (ระเบียนพนักงาน) และ "Attendance" (บันทึกการทำงาน)
 *    -> และจะนำข้อมูลพนักงานทั้ง 41 คน ลงใน Google Sheet ให้อัตโนมัติทันที!
 * 6. กดปุ่มสีน้ำเงินด้านขวาบน "การทำให้ใช้งานได้" (Deploy) > "การทำให้ใช้งานได้รายการใหม่" (New deployment)
 * 7. เลือกประเภทเป็น "เว็บแอปพลิเคชัน" (Web app)
 * 8. ตั้งค่า:
 *    - คำอธิบาย: PD Workforce & Attendance Webhook
 *    - ปฏิบัติการในฐานะ: ตัวฉัน (Me)
 *    - ผู้ที่มีสิทธิ์เข้าถึง: ทุกคน (Anyone)  <-- สำคัญมาก! เพื่อให้หน้าเว็บส่งข้อมูลเข้ามาได้
 * 9. กดปุ่ม "ทำให้ใช้งานได้" (Deploy) แล้วคัดลอก "URL เว็บแอป" (Web App URL)
 * 10. นำ URL ไปใส่ในหน้าเว็บ เพื่อให้ซิงค์ข้อมูลอัตโนมัติ
 * =========================================================================
 */

// Google Drive Folder สำหรับเก็บรูปพนักงานฝ่ายผลิต
var PHOTO_FOLDER_ID = '1u2byqrl5WZ-mByOcMenp2MGrc19XJDhC';

/**
 * =========================================================================
 * 1. ฟังก์ชันแปลงรูปในคอลัมน์ O ทั้งหมดเป็นลิงก์ Google Drive และกู้คืนวันที่ (รันฟังก์ชันนี้ได้ทันที!)
 * - แปลงชื่อไฟล์เดิม (เช่น emp_24520003_...jpg) และ Base64 ในคอลัมน์ O ให้เป็นลิงก์ Google Drive ทั้งหมด
 * - กู้คืนวันเริ่มงานและวันเกิดที่เคยแหว่ง และล้างค่า T17:00:00.000Z ให้เป็น YYYY-MM-DD
 * =========================================================================
 */
function repairAndFixEmployeeSheet() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = getEmployeeSheet(ss);
  var lastRow = sheet.getLastRow();
  if (lastRow <= 1) {
    Logger.log('ไม่มีข้อมูลในชีต');
    return { success: false, message: 'ไม่มีข้อมูลในชีต' };
  }

  // ตั้งค่าฟอร์แมตคอลัมน์ J (วันเริ่มงาน), K (วันเกิด), L (เบอร์โทร), P (วันที่มีผล) เป็น Plain Text เพื่อป้องกัน T17:00:00.000Z
  sheet.getRange(2, 10, lastRow - 1, 3).setNumberFormat('@');
  sheet.getRange(2, 16, lastRow - 1, 1).setNumberFormat('@');

  var numCols = Math.max(17, sheet.getLastColumn());
  var data = sheet.getRange(2, 1, lastRow - 1, numCols).getValues();

  // สร้างแผนที่ข้อมูลวันเริ่มงานและวันเกิดจาก SEED_EMPLOYEES ทั้ง 41 คน เพื่อเติมเต็มช่องที่ว่าง
  var seedMap = {};
  if (typeof SEED_EMPLOYEES !== 'undefined' && Array.isArray(SEED_EMPLOYEES)) {
    for (var s = 0; s < SEED_EMPLOYEES.length; s++) {
      var se = SEED_EMPLOYEES[s];
      seedMap[String(se.employee_code).trim()] = {
        start: se.start_date || '',
        birth: se.birth_date || ''
      };
    }
  }

  for (var i = 0; i < data.length; i++) {
    var code = String(data[i][0]).trim();
    
    // ล้างค่า T17:00:00.000Z หรือเติมวันเริ่มงาน/วันเกิดที่หายไป
    var curStart = formatGasDate(data[i][9]);
    var curBirth = formatGasDate(data[i][10]);
    if (!curStart && seedMap[code]) curStart = seedMap[code].start;
    if (!curBirth && seedMap[code]) curBirth = seedMap[code].birth;
    data[i][9] = curStart;
    data[i][10] = curBirth;
    data[i][15] = formatGasDate(data[i][15]);

    // ล้างข้อความ Base64 ที่ค้างในช่องรูปภาพ (Col O) แล้วเซฟขึ้น Drive แทน
    var avatarVal = String(data[i][14] || '').trim();
    if (avatarVal.indexOf('data:image') === 0 || avatarVal.length > 200) {
      try {
        var driveUrl = saveAvatarToDrive(avatarVal, code);
        data[i][14] = driveUrl;
      } catch (e) {
        data[i][14] = code + '.jpg';
      }
    }
  }

  sheet.getRange(2, 1, data.length, numCols).setValues(data);

  // แปลงชื่อไฟล์ (emp_24520003_...jpg) ในคอลัมน์ O ทั้งหมดให้เป็นลิงก์ Google Drive
  var linkRes = updateAllAvatarLinksFromDrive();
  Logger.log('แปลงลิงก์รูปภาพ Google Drive สำเร็จ ' + (linkRes.count || 0) + ' คน และจัดระเบียบวันที่เรียบร้อยแล้ว');
  return { success: true, count: linkRes.count, message: 'แปลงรูปเป็นลิงก์ Google Drive ทั้งหมด (' + (linkRes.count || 0) + ' คน) เรียบร้อยแล้ว' };
}

/**
 * Helper: ค้นหาชีตระเบียนพนักงาน (รองรับทั้งชื่อภาษาไทย 'ระเบียนพนักงาน' / 'ทะเบียนพนักงาน' และ 'Employees')
 */
function getEmployeeSheet(ss) {
  var s = ss || SpreadsheetApp.getActiveSpreadsheet();
  return s.getSheetByName('Employees') || s.getSheetByName('ทะเบียนพนักงาน') || s.getSheetByName('ระเบียนพนักงาน') || s.insertSheet('Employees');
}

/**
 * Helper: ค้นหาชีตบันทึกการทำงาน (รองรับทั้งชื่อภาษาไทย 'บันทึกการทำงาน' และ 'Attendance')
 */
function getAttendanceSheet(ss) {
  var s = ss || SpreadsheetApp.getActiveSpreadsheet();
  return s.getSheetByName('Attendance') || s.getSheetByName('บันทึกการทำงาน') || s.insertSheet('Attendance');
}

/**
 * บันทึกรูปภาพ Base64 ลงใน Google Drive โฟลเดอร์ "รูปภาพพนักงานฝ่ายผลิต"
 * แทนที่รูปเก่า และสร้างลิงก์ตรงสำหรับเปิดดูได้ทุกเครื่อง
 * @param {string} base64Data ข้อมูลรูปภาพ (Base64 data:image/...)
 * @param {string} employeeCode รหัสพนักงาน
 * @return {string} URL ตรงของรูปภาพบน Google Drive
 */
function saveAvatarToDrive(base64Data, employeeCode) {
  if (!base64Data || typeof base64Data !== 'string') return base64Data || '';
  if (base64Data.indexOf('data:image/') !== 0) return base64Data; // ถ้าเป็น URL หรือชื่อไฟล์อยู่แล้วให้คืนค่าเดิม

  try {
    var folder = DriveApp.getFolderById(PHOTO_FOLDER_ID);
    var cleanBase64 = base64Data.replace(/^data:image\/\w+;base64,/, '');
    var bytes = Utilities.base64Decode(cleanBase64);
    var codeStr = String(employeeCode).trim();
    var filename = codeStr + '.jpg';

    // 1. ค้นหาและลบรูปเดิมของพนักงานคนนี้ใน Drive ทั้งหมด (แทนที่รูปเก่า)
    var allFiles = folder.getFiles();
    while (allFiles.hasNext()) {
      var oldFile = allFiles.next();
      var oldName = oldFile.getName();
      // ลบถ้าชื่อไฟล์มีรหัสพนักงานคนนี้อยู่
      if (oldName.indexOf(codeStr) !== -1) {
        oldFile.setTrashed(true);
      }
    }

    // 2. สร้างไฟล์รูปภาพใหม่ใน Google Drive
    var newFile = folder.createFile(Utilities.newBlob(bytes, 'image/jpeg', filename));
    newFile.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
    
    // 3. คืนค่า Google Drive Thumbnail URL ที่สามารถแสดงผลบนเว็บและมือถือได้ทันที
    return 'https://drive.google.com/thumbnail?id=' + newFile.getId() + '&sz=w500';
  } catch (err) {
    Logger.log('Error saving avatar to Drive: ' + err.toString());
    return base64Data;
  }
}

/**
 * ฟังก์ชันสร้าง/ผูกลิงก์รูปภาพพนักงานทั้งหมดจาก Google Drive ลงในคอลัมน์ O ของ Google Sheet
 * ค้นหาไฟล์รูปในโฟลเดอร์ "รูปภาพพนักงานฝ่ายผลิต" ตามรหัสพนักงาน หรือชื่อไฟล์เดิม (emp_24520003_...jpg)
 * และแปลงเป็น URL ภาพตรง (Direct Thumbnail URL) ที่เปิดดูได้ทันทีทุกเครื่อง
 */
function updateAllAvatarLinksFromDrive() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var sheet = getEmployeeSheet(ss);
  var folder = DriveApp.getFolderById(PHOTO_FOLDER_ID);
  
  // สร้าง Index ไฟล์ทั้งหมดในโฟลเดอร์รูปภาพ (ทั้งตามรหัสพนักงาน 8 หลัก และตามชื่อไฟล์เต็ม)
  var files = folder.getFiles();
  var fileMapByCode = {}; // รหัส 8 หลัก => fileId
  var fileMapByName = {}; // ชื่อไฟล์ => fileId
  while (files.hasNext()) {
    var f = files.next();
    var fname = f.getName();
    var fid = f.getId();
    fileMapByName[fname.trim()] = fid;
    var m = fname.match(/(\d{8})/);
    if (m) {
      fileMapByCode[m[1]] = fid;
    }
  }
  
  var lastRow = sheet.getLastRow();
  if (lastRow <= 1) return { success: false, message: 'ไม่มีข้อมูลพนักงานในชีต' };
  
  var codes = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
  var avatars = sheet.getRange(2, 15, lastRow - 1, 1).getValues();
  
  var updated = 0;
  for (var i = 0; i < codes.length; i++) {
    var code = String(codes[i][0]).trim();
    var currentAvatar = String(avatars[i][0] || '').trim();
    
    // ถ้าในช่องมี Base64 ค้างอยู่ ให้อัปโหลดขึ้น Drive แล้วแปลงเป็นลิงก์ทันที
    if (currentAvatar.indexOf('data:image') === 0) {
      avatars[i][0] = saveAvatarToDrive(currentAvatar, code);
      updated++;
      continue;
    }

    // ค้นหา File ID จากชื่อไฟล์เดิมในช่อง O หรือจากรหัสพนักงาน 8 หลัก
    var fileId = fileMapByName[currentAvatar] || fileMapByCode[code];
    if (!fileId && currentAvatar) {
      var mAvatar = currentAvatar.match(/(\d{8})/);
      if (mAvatar && fileMapByCode[mAvatar[1]]) {
        fileId = fileMapByCode[mAvatar[1]];
      }
    }

    if (fileId) {
      try {
        var file = DriveApp.getFileById(fileId);
        file.setSharing(DriveApp.Access.ANYONE_WITH_LINK, DriveApp.Permission.VIEW);
      } catch(e) {}
      
      var linkUrl = 'https://drive.google.com/thumbnail?id=' + fileId + '&sz=w500';
      avatars[i][0] = linkUrl;
      updated++;
    }
  }
  
  // บันทึกลิงก์รูปภาพลงคอลัมน์ O ใน Google Sheet
  sheet.getRange(2, 15, lastRow - 1, 1).setValues(avatars);
  return { success: true, count: updated, message: 'แปลงชื่อไฟล์ในคอลัมน์ O เป็นลิงก์ Google Drive สำเร็จ ' + updated + ' คน' };
}



// ข้อมูลพนักงานตั้งต้นทั้ง 41 คน
var SEED_EMPLOYEES = [{"id":1,"employee_code":"24520003","title":"นาย","first_name":"คะนอง","last_name":"ศิริวรรณ","nickname":"นอง","avatar":"24520003.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Dry End","shift":"A","station":"Stacker","start_date":"2009-01-06","probation_status":"passed","probation_pass_date":"2009-05-06","probation_notes":"ผ่านการทดลองงานระดับดีเยี่ยม","birth_date":"1992-02-01","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-09-22 16:44:58","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":2,"employee_code":"24560128","title":"นาย","first_name":"ภูธร","last_name":"กะรัมย์","nickname":"ธร","avatar":"24560128.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M1","start_date":"2013-05-10","probation_status":"passed","probation_pass_date":"2013-09-10","probation_notes":"ผ่านการทดลองงาน","birth_date":"1979-05-15","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 12:45:51","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":3,"employee_code":"24580206","title":"นาย","first_name":"บุญใสย์","last_name":"จันทร์ลี","nickname":"เขียด","avatar":"24580206.jpg","department":"BHS","machine":"BHS","position":"หัวหน้ากะ","shift":"A","station":"Host","start_date":"2015-06-02","probation_status":"passed","probation_pass_date":"2015-10-02","probation_notes":"ผ่านการทดลองงาน","birth_date":"1988-03-20","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-09-22 16:44:58","skill_level":4,"m1":100,"m2":100,"m3":100,"m4":100,"m5":100,"df":100,"single_b":100,"single_c":100,"control_room":100,"stacker":100,"penalty_count":0},{"id":5,"employee_code":"24610062","title":"นาย","first_name":"สาทร","last_name":"วาที","nickname":"ทร","avatar":"24610062.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"DOUBLE","start_date":"2018-02-05","probation_status":"passed","probation_pass_date":"2018-06-05","probation_notes":"ผ่านการทดลองงาน","birth_date":"1981-01-10","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 12:45:51","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":7,"employee_code":"24620035","title":"นาย","first_name":"ภานุเมศ","last_name":"โสสุด","nickname":"เลน","avatar":"24620035.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"SF-C","start_date":"2019-01-11","probation_status":"passed","probation_pass_date":"2019-05-11","probation_notes":"ผ่านการทดลองงาน","birth_date":"1995-05-20","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 12:45:51","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":10,"employee_code":"24620283","title":"นาย","first_name":"อภิรักษ์","last_name":"ศรุติปกรณ์","nickname":"บอล","avatar":"24620283.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"SF-B","start_date":"2019-07-05","probation_status":"passed","probation_pass_date":"2019-11-05","probation_notes":"ผ่านการทดลองงาน","birth_date":"1992-05-08","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:56:15","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":13,"employee_code":"24630152","title":"นาย","first_name":"วันชัย","last_name":"สอนโพนงาม","nickname":"เล็ก","avatar":"24630152.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"Control","start_date":"2020-09-14","probation_status":"passed","probation_pass_date":"2021-01-14","probation_notes":"ผ่านการทดลองงาน","birth_date":"1995-02-18","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:56:15","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":17,"employee_code":"24650104","title":"นาย","first_name":"ชยังกูร","last_name":"ดอนตะโก","nickname":"นิว","avatar":"24650104.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M3","start_date":"2022-09-07","probation_status":"passed","probation_pass_date":"2023-01-07","probation_notes":"ผ่านการทดลองงาน","birth_date":"1997-03-18","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:49:18","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":19,"employee_code":"24660114","title":"นาย","first_name":"สมพงษ์","last_name":"จันทร์ลี","nickname":"เอ๋","avatar":"24660114.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M5","start_date":"2023-06-01","probation_status":"passed","probation_pass_date":"2023-10-01","probation_notes":"ผ่านการทดลองงาน","birth_date":"1994-02-25","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:50:03","skill_level":2,"m1":100,"m2":100,"m3":80,"m4":100,"m5":100,"df":40,"single_b":20,"single_c":20,"control_room":0,"stacker":0,"penalty_count":0},{"id":18,"employee_code":"24690023","title":"นาย","first_name":"จุทาวัธร","last_name":"บรรพแก้ว","nickname":"จุทา","avatar":"","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M2","start_date":"2026-01-01","probation_status":"passed","probation_pass_date":"2026-05-01","probation_notes":"อยู่ระหว่างการทดลองงาน","birth_date":"2002-06-10","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:49:49","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":6,"employee_code":"24620017","title":"นาย","first_name":"อิสระ","last_name":"สัจบาล","nickname":"อิส","avatar":"24620017.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"M1","start_date":"2019-01-11","probation_status":"passed","probation_pass_date":"2019-05-11","probation_notes":"ผ่านการทดลองงาน","birth_date":"1992-03-15","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 12:45:51","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":8,"employee_code":"24620086","title":"นาย","first_name":"อดิเรก","last_name":"หาญกล้า","nickname":"โอ","avatar":"24620086.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"SF-B","start_date":"2019-02-20","probation_status":"passed","probation_pass_date":"2019-06-20","probation_notes":"ผ่านการทดลองงาน","birth_date":"1995-07-10","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:56:15","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":9,"employee_code":"24620217","title":"นาย","first_name":"สุธานนท์","last_name":"คนเพียร","nickname":"ทิศโก้","avatar":"24620217.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"DOUBLE","start_date":"2019-06-04","probation_status":"passed","probation_pass_date":"2019-10-04","probation_notes":"ผ่านการทดลองงาน","birth_date":"1999-03-12","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:36:17","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":11,"employee_code":"24620291","title":"นาย","first_name":"ภาคภูมิ","last_name":"ดีสม","nickname":"ไนท์","avatar":"24620291.jpg","department":"ฝ่ายผลิต","machine":"BHS","position":"หัวหน้ากะ","shift":"B","station":"Host","start_date":"2019-07-08","probation_status":"passed","probation_pass_date":"2019-11-08","probation_notes":"ผ่านการทดลองงาน","birth_date":"1999-08-22","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-09-10 12:02:39","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":14,"employee_code":"24640005","title":"นาย","first_name":"อุเทน","last_name":"พรมวงค์","nickname":"เท่","avatar":"24640005.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"SF-C","start_date":"2021-01-28","probation_status":"passed","probation_pass_date":"2021-05-28","probation_notes":"ผ่านการทดลองงาน","birth_date":"1994-11-05","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:49:32","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":15,"employee_code":"24640067","title":"นาย","first_name":"วันชนะ","last_name":"หีบแก้ว","nickname":"เซียงโก้","avatar":"24640067.jpg","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"Stacker","start_date":"2021-07-19","probation_status":"passed","probation_pass_date":"2021-11-19","probation_notes":"ผ่านการทดลองงาน","birth_date":"2001-07-12","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-29 10:13:34","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":12,"employee_code":"24680046","title":"นาย","first_name":"สายนธี","last_name":"พิมน์ทรัพน์","nickname":"บิ๊ก","avatar":"24680046.png","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"M3","start_date":"2025-06-01","probation_status":"passed","probation_pass_date":"2025-10-01","probation_notes":"ผ่านการทดลองงาน","birth_date":"2004-04-10","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:51:06","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":16,"employee_code":"24680048","title":"นาย","first_name":"ธนากร","last_name":"ทิพย์ชัย","nickname":"เก้า","avatar":"24680048.png","department":"BHS","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"M5","start_date":"2025-06-01","probation_status":"passed","probation_pass_date":"2025-10-01","probation_notes":"ผ่านการทดลองงาน","birth_date":"2003-09-15","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-08-28 14:51:28","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":20,"employee_code":"24690063","title":"นาย","first_name":"ทักษ์ดนัย","last_name":"เพชรประไพ","nickname":"บอส","avatar":"24690063.jpg","department":"ฝ่ายผลิต","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"M2","start_date":"2026-06-01","probation_status":"probation","probation_pass_date":"2026-10-01","probation_notes":"อยู่ระหว่างการทดลองงาน (เริ่ม มิ.ย. 2569)","birth_date":"2005-08-10","phone":"","status":"probation","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-09-10 09:41:49","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":21,"employee_code":"24690065","title":"นาย","first_name":"ธนาธิป","last_name":"สระทองเงิน","nickname":"ต้น","avatar":"24690065.jpg","department":"ฝ่ายผลิต","machine":"BHS","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"Control","start_date":"2026-06-01","probation_status":"probation","probation_pass_date":"2026-10-01","probation_notes":"อยู่ระหว่างการทดลองงาน (เริ่ม มิ.ย. 2569)","birth_date":"2004-10-15","phone":"","status":"probation","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-09-10 11:58:12","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":4,"employee_code":"24610007","title":"นาย","first_name":"อิศราวุธ","last_name":"คงเจริญ","nickname":"อาร์ท","avatar":"24610007.jpg","department":"ฝ่ายผลิต","machine":"BHS","position":"หัวหน้าแผนก","shift":"AB","station":"สำนักงาน BHS","start_date":"2018-01-06","probation_status":"passed","probation_pass_date":"2018-05-06","probation_notes":"ผ่านการทดลองงาน","birth_date":"1997-10-22","phone":"","status":"active","notes":"","created_by":null,"created_at":"2026-08-28 11:45:56","updated_at":"2026-09-11 17:24:38","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":41,"employee_code":"24600010","title":"นาย","first_name":"วิทยา","last_name":"เสียงหวาน","nickname":"เค","avatar":"24600010.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"SF-C","start_date":"2017-02-01","probation_status":"passed","probation_pass_date":"2017-06-01","probation_notes":"","birth_date":"1993-06-02","phone":"097-1684557","status":"active","notes":"Single-C","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:04:08","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":39,"employee_code":"24610139","title":"นาย","first_name":"KYAW PAYING PHYO","last_name":"-","nickname":"ไป๋","avatar":"24610139.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"SF-B","start_date":"2018-03-16","probation_status":"passed","probation_pass_date":"2018-07-16","probation_notes":"","birth_date":"1992-04-02","phone":"098-8900100","status":"active","notes":"Single-B+M4","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:04:33","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":34,"employee_code":"24620057","title":"นาย","first_name":"นันทวุฒิ","last_name":"จุลทัศน์","nickname":"นิ๊ก","avatar":"24620057.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"หัวหน้ากะ","shift":"A","station":"Host","start_date":"2019-01-18","probation_status":"passed","probation_pass_date":"2019-05-18","probation_notes":"","birth_date":"1996-09-09","phone":"095-7176279","status":"active","notes":"หัวหน้ากะ A (Host)","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-24 12:40:45","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":37,"employee_code":"24680098","title":"นาย","first_name":"สมชาย","last_name":"เกษตรไพร","nickname":"ปุ๊","avatar":"24680098.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"DOUBLE","start_date":"2025-11-30","probation_status":"passed","probation_pass_date":"2026-03-30","probation_notes":"","birth_date":"1998-10-30","phone":"065-4949408","status":"active","notes":"Double Facer","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:48:08","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":44,"employee_code":"24690099","title":"นาย","first_name":"Phone Nyat Ou","last_name":"-","nickname":"อู","avatar":"24690099.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Dry End","shift":"A","station":"Stacker","start_date":"2026-01-01","probation_status":"passed","probation_pass_date":"2026-05-01","probation_notes":"","birth_date":"1985-03-11","phone":"083-1140447","status":"active","notes":"Sup","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-19 09:37:05","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":43,"employee_code":"24690005","title":"นาย","first_name":"วาทิตย์","last_name":"มุ่งรายกลาง","nickname":"นาย","avatar":"24690005.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M1","start_date":"2026-01-11","probation_status":"passed","probation_pass_date":"2026-05-11","probation_notes":"","birth_date":"2001-10-21","phone":"062-5713815","status":"active","notes":"M1","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:48:44","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":40,"employee_code":"24690009","title":"นาย","first_name":"กิตติวุฒิ","last_name":"ณ บางช้าง","nickname":"เพิร์ท","avatar":"24690009.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M3","start_date":"2026-01-19","probation_status":"passed","probation_pass_date":"2026-05-19","probation_notes":"","birth_date":"2003-11-29","phone":"085-5160110","status":"active","notes":"M3","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:50:15","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":35,"employee_code":"24690013","title":"นาย","first_name":"ธนพงษ์","last_name":"โพธิ์ผาง","nickname":"บอส","avatar":"24690013.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Control","shift":"A","station":"Control","start_date":"2026-01-26","probation_status":"passed","probation_pass_date":"2026-05-26","probation_notes":"","birth_date":"1995-01-04","phone":"061-2485230","status":"active","notes":"Control Room","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:51:57","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":42,"employee_code":"24690011","title":"นาย","first_name":"รุ่งตะวัน","last_name":"แดงขาว","nickname":"ไนท์","avatar":"24690011.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M2","start_date":"2026-01-26","probation_status":"passed","probation_pass_date":"2026-05-26","probation_notes":"","birth_date":"2005-09-14","phone":"095-8254840","status":"active","notes":"M2","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:52:09","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":36,"employee_code":"24690029","title":"นาย","first_name":"ทรงเกียรติ","last_name":"วารีคีรี","nickname":"กบ","avatar":"24690029.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Dry End","shift":"A","station":"Stacker","start_date":"2026-05-04","probation_status":"passed","probation_pass_date":"2026-09-04","probation_notes":"","birth_date":"1995-02-24","phone":"097-9689866","status":"active","notes":"STACKER","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:52:54","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":38,"employee_code":"24690047","title":"นาย","first_name":"ภานุวัฒน์","last_name":"พุ่มฉัตร","nickname":"บีม","avatar":"24690047.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"A","station":"M5","start_date":"2026-07-01","probation_status":"probation","probation_pass_date":"2026-11-01","probation_notes":"","birth_date":"2005-12-27","phone":"062-4547024","status":"active","notes":"M5","created_by":1,"created_at":"2026-09-05 08:54:43","updated_at":"2026-09-05 09:53:04","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":45,"employee_code":"24620289","title":"นาย","first_name":"วุฒิชัย","last_name":"โสภากุ","nickname":"เจ","avatar":"24620289.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"Host","start_date":"2019-07-08","probation_status":"passed","probation_pass_date":"2019-11-08","probation_notes":"","birth_date":"1996-01-14","phone":"080-7294362","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:24:16","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":53,"employee_code":"24640081","title":"นาย","first_name":"กิจจา","last_name":"เคลือบยิ้ม","nickname":"แจ็ค","avatar":"24640081.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"DOUBLE","start_date":"2021-08-25","probation_status":"passed","probation_pass_date":"2021-12-25","probation_notes":"","birth_date":"1982-02-22","phone":"098-8870436","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:20:48","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":49,"employee_code":"24640029","title":"นาย","first_name":"นุพล","last_name":"อุปพงษ์","nickname":"ออฟ","avatar":"24640029.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"SF-C","start_date":"2022-01-11","probation_status":"passed","probation_pass_date":"2022-05-11","probation_notes":"","birth_date":"2000-07-31","phone":"096-1788120","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:37:26","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":51,"employee_code":"24670027","title":"นาย","first_name":"วันชัย","last_name":"พรมเสน","nickname":"เปา","avatar":"24670027.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Dry End","shift":"B","station":"Stacker","start_date":"2024-03-07","probation_status":"passed","probation_pass_date":"2024-07-07","probation_notes":"","birth_date":"1994-05-10","phone":"098-5913702","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:23:55","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":50,"employee_code":"24680060","title":"นาย","first_name":"สรศักดิ์","last_name":"ขันชะลี","nickname":"เวฟ","avatar":"24680060.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"SF-B","start_date":"2025-06-18","probation_status":"passed","probation_pass_date":"2025-10-18","probation_notes":"","birth_date":"2005-05-13","phone":"094-7356157","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:25:32","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":46,"employee_code":"24680090","title":"นาย","first_name":"อติชาต","last_name":"เปรียบนาน","nickname":"แอ๊ะ","avatar":"24680090.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Control","shift":"B","station":"Control","start_date":"2025-09-23","probation_status":"passed","probation_pass_date":"2026-01-23","probation_notes":"","birth_date":"2005-09-18","phone":"099-4419729","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:23:38","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":47,"employee_code":"24680089","title":"นาย","first_name":"สรรค์ชัย","last_name":"ชูสุวรรณ","nickname":"แต้ม","avatar":"24680089.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"M1","start_date":"2025-10-23","probation_status":"passed","probation_pass_date":"2026-02-23","probation_notes":"","birth_date":"1988-12-28","phone":"062-2718028","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:28:50","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":52,"employee_code":"24690018","title":"นาย","first_name":"จักรพงษ์","last_name":"หงษ์คำเมือง","nickname":"โอ๊ต","avatar":"24690018.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"M5","start_date":"2026-02-23","probation_status":"passed","probation_pass_date":"2026-06-23","probation_notes":"","birth_date":"1998-04-09","phone":"083-7150553","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:29:12","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0},{"id":48,"employee_code":"24690027","title":"นาย","first_name":"ประพันธ์","last_name":"สุนิกร","nickname":"ตั้ง","avatar":"24690027.jpg","department":"ฝ่ายผลิต","machine":"YUELI","position":"ผู้ปฏิบัติงาน Wet End","shift":"B","station":"M2","start_date":"2026-04-20","probation_status":"passed","probation_pass_date":"2026-08-20","probation_notes":"","birth_date":"1991-09-04","phone":"094-4285121","status":"active","notes":"","created_by":1,"created_at":"2026-09-09 14:34:23","updated_at":"2026-09-09 17:28:06","skill_level":null,"m1":null,"m2":null,"m3":null,"m4":null,"m5":null,"df":null,"single_b":null,"single_c":null,"control_room":null,"stacker":null,"penalty_count":0}];



/**
 * ฟังก์ชันสร้างชีตและลงข้อมูลพนักงาน 41 คนอัตโนมัติ (กดรันครั้งเดียว)
 */
function setupSheetAndSeedEmployees() {
  var ss = SpreadsheetApp.getActiveSpreadsheet();

  // 1. เตรียมแท็บระเบียนพนักงาน (ระเบียนพนักงาน / Employees)
  var empSheet = getEmployeeSheet(ss);
  empSheet.clear();

  // Header ระเบียนพนักงาน
  var empHeaders = [
    'รหัสพนักงาน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ชื่อเล่น', 
    'เครื่องจักร', 'กะการทำงาน', 'จุดประจำการ (Station)', 'ตำแหน่ง', 
    'วันเริ่มงาน', 'วันเกิด', 'เบอร์โทรศัพท์', 'สถานะทดลองงาน', 'สถานะการทำงาน',
    'รูปภาพ (Avatar)', 'วันที่มีผล (พ้นสภาพ)', 'หมายเหตุ'
  ];
  empSheet.getRange(1, 1, 1, empHeaders.length).setValues([empHeaders]);
  empSheet.getRange(1, 1, 1, empHeaders.length)
    .setFontWeight('bold')
    .setBackground('#ff7900')
    .setFontColor('#ffffff')
    .setHorizontalAlignment('center');
  empSheet.setFrozenRows(1);

  // ลงข้อมูลพนักงาน 41 คน
  var empRows = [];
  for (var i = 0; i < SEED_EMPLOYEES.length; i++) {
    var e = SEED_EMPLOYEES[i];
    empRows.push([
      String(e.employee_code || ''),
      e.title || '',
      e.first_name || '',
      e.last_name || '',
      e.nickname || '',
      e.machine || '',
      e.shift || '',
      e.station || '',
      e.position || '',
      e.start_date || '',
      e.birth_date || '',
      e.phone || '',
      e.probation_status || 'passed',
      e.status || 'active',
      e.avatar || ''
    ]);
  }
  if (empRows.length > 0) {
    empSheet.getRange(2, 1, empRows.length, empHeaders.length).setValues(empRows);
  }
  empSheet.autoResizeColumns(1, empHeaders.length);

  // 2. เตรียมแท็บ บันทึกการทำงาน (บันทึกการทำงาน / Attendance)
  var attSheet = getAttendanceSheet(ss);
  if (attSheet.getLastRow() === 0) {
    var attHeaders = [
      'วันที่', 'รหัสพนักงาน', 'ชื่อ-นามสกุล', 'ชื่อเล่น', 
      'เครื่องจักร', 'กะ', 'จุดประจำการ', 'สถานะการปฏิบัติงาน', 
      'จำนวนวัน', 'หมายเหตุ', 'เวลาที่บันทึก'
    ];
    attSheet.getRange(1, 1, 1, attHeaders.length).setValues([attHeaders]);
    attSheet.getRange(1, 1, 1, attHeaders.length)
      .setFontWeight('bold')
      .setBackground('#10b981')
      .setFontColor('#ffffff')
      .setHorizontalAlignment('center');
    attSheet.setFrozenRows(1);
    attSheet.autoResizeColumns(1, attHeaders.length);
  }

  Logger.log('ลงข้อมูลพนักงาน 41 คนในแท็บ ' + empSheet.getName() + ' เรียบร้อยแล้ว!');
}

/**
 * รับคำขอ POST จากหน้าเว็บเมื่อมีการเพิ่มพนักงาน หรือบันทึกเวลาทำงาน
 */
function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.waitLock(30000); // รอคิวเขียน 30 วินาที ป้องกันข้อมูลชนกัน

  try {
    if (!e || !e.postData || !e.postData.contents) {
      return ContentService.createTextOutput(JSON.stringify({ success: false, error: 'No payload received' }))
        .setMimeType(ContentService.MimeType.JSON);
    }

    var payload = JSON.parse(e.postData.contents);
    var action = payload.action;
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var result = { success: true };

    // 1. เพิ่มพนักงานใหม่ลงชีต Employees / ระเบียนพนักงาน
    if (action === 'add_employee') {
      var sheet = getEmployeeSheet(ss);
      var emp = payload.employee || payload;
      var codeStr = String(emp.employee_code || '');
      var driveAvatarUrl = saveAvatarToDrive(emp.avatar, codeStr);
      var newRow = [
        codeStr,
        emp.title || '',
        emp.first_name || '',
        emp.last_name || '',
        emp.nickname || '',
        emp.machine || 'BHS',
        emp.shift || 'A',
        emp.station || '',
        emp.position || '',
        emp.start_date || '',
        emp.birth_date || '',
        emp.phone || '',
        emp.probation_status || 'passed',
        emp.status || 'active',
        driveAvatarUrl || ''
      ];
      sheet.appendRow(newRow);
      result.avatar_url = driveAvatarUrl;
      result.message = 'บันทึกพนักงานใหม่ ' + (emp.first_name || '') + ' ลง Google Sheet สำเร็จ';
    }

    // 1.1 ซิงค์พนักงานทั้งหมด 41 คนอัตโนมัติ (Force Sync All)
    else if (action === 'sync_all_employees' || action === 'seed_employees') {
      setupSheetAndSeedEmployees();
      result.message = 'ซิงค์ข้อมูลพนักงานทั้งหมด 41 คนลงแท็บ ระเบียนพนักงาน เรียบร้อยแล้ว';
    }

    // 2. แก้ไขข้อมูลพนักงานในชีต Employees / ระเบียนพนักงาน (แบบปลอดภัย - ไม่ลบข้อมูลที่มีอยู่เดิม)
    else if (action === 'edit_employee' || action === 'update_employee') {
      var sheet = getEmployeeSheet(ss);
      var emp = payload.employee || payload;
      var codeStr = String(emp.employee_code || '');
      var lastRow = sheet.getLastRow();
      var foundRow = -1;

      if (lastRow > 1) {
        var dataRange = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
        for (var i = 0; i < dataRange.length; i++) {
          if (String(dataRange[i][0]).trim() === codeStr) {
            foundRow = i + 2;
            break;
          }
        }
      }

      if (foundRow > 0) {
        // อ่านข้อมูลแถวเดิมในชีต เพื่อรักษาค่าที่ไม่ได้ส่งมา ไม่ให้หายเด็ดขาด
        var oldRow = sheet.getRange(foundRow, 1, 1, 17).getValues()[0];
        
        // จัดการรูปภาพ: ถ้าส่ง base64 มา ให้อัปโหลดลง Drive และได้ URL ใหม่ ถ้าไม่ได้ส่งมาให้ใช้รูปเดิมในชีต
        var driveAvatarUrl = oldRow[14] || '';
        if (emp.avatar && String(emp.avatar).indexOf('data:image/') === 0) {
          driveAvatarUrl = saveAvatarToDrive(emp.avatar, codeStr);
        } else if (emp.avatar && String(emp.avatar).indexOf('http') === 0) {
          driveAvatarUrl = emp.avatar;
        }

        var newStatus = emp.status || oldRow[13] || 'active';
        var isBackToActive = (newStatus === 'active');

        var rowData = [
          codeStr,
          emp.title || oldRow[1] || '',
          emp.first_name || oldRow[2] || '',
          emp.last_name || oldRow[3] || '',
          emp.nickname || oldRow[4] || '',
          emp.machine || oldRow[5] || 'BHS',
          emp.shift || oldRow[6] || 'A',
          emp.station || oldRow[7] || '',
          emp.position || oldRow[8] || '',
          emp.start_date || oldRow[9] || '',   // รักษา วันเริ่มงาน
          emp.birth_date || oldRow[10] || '', // รักษา วันเกิด
          emp.phone || oldRow[11] || '',      // รักษา เบอร์โทร
          emp.probation_status || oldRow[12] || 'passed',
          newStatus,
          driveAvatarUrl,
          isBackToActive ? '' : (emp.resigned_date || oldRow[15] || ''), // ล้างวันที่มีผลถ้ากลับมา active
          isBackToActive ? '' : (emp.notes || oldRow[16] || '')          // ล้างหมายเหตุถ้ากลับมา active
        ];

        sheet.getRange(foundRow, 1, 1, rowData.length).setValues([rowData]);
        result.avatar_url = driveAvatarUrl;
        result.message = 'อัปเดตข้อมูลพนักงาน ' + (emp.first_name || '') + ' สำเร็จ (ข้อมูลอื่นคงเดิม)';
      } else {
        var driveAvatarUrl = saveAvatarToDrive(emp.avatar, codeStr);
        var rowData = [
          codeStr,
          emp.title || '',
          emp.first_name || '',
          emp.last_name || '',
          emp.nickname || '',
          emp.machine || 'BHS',
          emp.shift || 'A',
          emp.station || '',
          emp.position || '',
          emp.start_date || '',
          emp.birth_date || '',
          emp.phone || '',
          emp.probation_status || 'passed',
          emp.status || 'active',
          driveAvatarUrl || '',
          emp.resigned_date || '',
          emp.notes || ''
        ];
        sheet.appendRow(rowData);
        result.avatar_url = driveAvatarUrl;
        result.message = 'บันทึกข้อมูลพนักงานใหม่ ' + (emp.first_name || '') + ' สำเร็จ';
      }
    }

    // 2.1 อัปเดตเฉพาะช่องรูปภาพ (Avatar) - คอลัมน์ O เท่านั้น ไม่แตะข้อมูลประวัติอื่นเด็ดขาด
    else if (action === 'update_avatar' || action === 'upload_avatar') {
      var sheet = getEmployeeSheet(ss);
      var base64Data = payload.image_base64 || payload.avatar || payload.base64;
      var codeStr = String(payload.employee_code || (payload.employee ? payload.employee.employee_code : '')).trim();

      if (!codeStr) {
        result.success = false;
        result.message = 'ไม่พบรหัสพนักงาน';
      } else {
        var driveAvatarUrl = saveAvatarToDrive(base64Data, codeStr);
        var lastRow = sheet.getLastRow();
        var foundRow = -1;
        if (lastRow > 1) {
          var codes = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
          for (var i = 0; i < codes.length; i++) {
            if (String(codes[i][0]).trim() === codeStr) {
              foundRow = i + 2;
              break;
            }
          }
        }

        if (foundRow > 0) {
          // เปลี่ยนเฉพาะคอลัมน์ที่ 15 (Column O: รูปภาพ Avatar) ช่องเดียวเท่านั้น!
          sheet.getRange(foundRow, 15).setValue(driveAvatarUrl);
          result.avatar_url = driveAvatarUrl;
          result.message = 'อัปเดตเฉพาะรูปภาพของพนักงาน ' + codeStr + ' สำเร็จ';
        } else {
          result.success = false;
          result.message = 'ไม่พบรหัสพนักงาน ' + codeStr + ' ในชีต';
        }
      }
    }

    // 2.2 อัปเดตเฉพาะสถานะพนักงาน (Status / วันที่มีผล / หมายเหตุ) แก้ไขใน Google Sheet ทันที!
    else if (action === 'update_status') {
      var sheet = getEmployeeSheet(ss);
      var codeStr = String(payload.employee_code || (payload.employee ? payload.employee.employee_code : '')).trim();
      var newStatus = String(payload.status || 'active').trim().toLowerCase();
      var resignedDate = payload.resigned_date || '';
      var notes = payload.notes || '';

      var lastRow = sheet.getLastRow();
      var foundRow = -1;
      if (lastRow > 1) {
        var codes = sheet.getRange(2, 1, lastRow - 1, 1).getValues();
        for (var i = 0; i < codes.length; i++) {
          if (String(codes[i][0]).trim() === codeStr) {
            foundRow = i + 2;
            break;
          }
        }
      }

      if (foundRow > 0) {
        // อัปเดตคอลัมน์ที่ 14 (Column N: สถานะการทำงาน)
        sheet.getRange(foundRow, 14).setValue(newStatus);

        // หากกดเปลี่ยนกลับมาเป็น ปกติ หรือ Active -> ให้เคลียร์วันที่มีผลและหมายเหตุทิ้งทันที
        if (newStatus === 'active') {
          sheet.getRange(foundRow, 16).setValue(''); // Column P: วันที่มีผล (พ้นสภาพ)
          sheet.getRange(foundRow, 17).setValue(''); // Column Q: หมายเหตุ
          result.message = 'ปรับสถานะ ' + codeStr + ' กลับมาปฏิบัติงานปกติ (Active) ใน Google Sheet สำเร็จ';
        } else {
          // หากเป็น ลาออก, ไล่ออก, พักงาน -> ใส่วันที่มีผล และหมายเหตุ
          sheet.getRange(foundRow, 16).setValue(resignedDate || '');
          sheet.getRange(foundRow, 17).setValue(notes || '');
          result.message = 'ปรับสถานะ ' + codeStr + ' เป็น ' + newStatus + ' ใน Google Sheet สำเร็จ';
        }
      } else {
        result.success = false;
        result.message = 'ไม่พบรหัสพนักงาน ' + codeStr + ' ในชีต';
      }
    }

    // 2.3 ซ่อมแซมและกู้คืนข้อมูลประวัติ (วันเริ่มงาน, วันเกิด) และผูกลิงก์รูปภาพทั้งหมด
    else if (action === 'repair_sheet' || action === 'repair_employees') {
      var repRes = repairAndFixEmployeeSheet();
      result.message = repRes.message;
    }

    // 2.2 ผูกลิงก์รูปภาพทั้งหมดจาก Drive โฟลเดอร์ "รูปภาพพนักงานฝ่ายผลิต" ลงชีต (คอลัมน์ O)
    else if (action === 'sync_avatar_links') {
      var syncRes = updateAllAvatarLinksFromDrive();
      result.count = syncRes.count;
      result.message = syncRes.message;
    }

    // 3. บันทึกการลงเวลาทำงาน / วันลา ลงชีต Attendance / บันทึกการทำงาน
    else if (action === 'save_attendance') {
      var sheet = getAttendanceSheet(ss);
      var rec = payload.record || payload;
      var timestamp = Utilities.formatDate(new Date(), 'Asia/Bangkok', 'yyyy-MM-dd HH:mm:ss');
      
      // ตรวจสอบว่ามีข้อมูลของวันและพนักงานคนนี้อยู่แล้วหรือไม่ เพื่อทำการอัปเดตแทนการเขียนซ้ำ
      var dateStr = String(rec.work_date || '');
      var codeStr = String(rec.employee_code || '');
      var lastRow = sheet.getLastRow();
      var foundRow = -1;

      if (lastRow > 1) {
        var dataRange = sheet.getRange(2, 1, lastRow - 1, 2).getValues();
        for (var i = 0; i < dataRange.length; i++) {
          var rDate = String(dataRange[i][0]);
          var rCode = String(dataRange[i][1]);
          if (rDate === dateStr && rCode === codeStr) {
            foundRow = i + 2;
            break;
          }
        }
      }

      var rowData = [
        dateStr,
        codeStr,
        rec.full_name || '',
        rec.nickname || '',
        rec.machine || '',
        rec.shift || '',
        rec.station || '',
        rec.status_label || rec.status || 'มาทำงานปกติ',
        Number(rec.days_count || 1.0),
        rec.remarks || '',
        timestamp
      ];

      if (foundRow > 0) {
        sheet.getRange(foundRow, 1, 1, rowData.length).setValues([rowData]);
        result.message = 'อัปเดตบันทึกการทำงานวันที่ ' + dateStr + ' สำเร็จ';
      } else {
        sheet.appendRow(rowData);
        result.message = 'บันทึกการทำงานวันที่ ' + dateStr + ' สำเร็จ';
      }
    }

    // 3. บันทึกการลงเวลาแบบกลุ่ม (Bulk Attendance)
    else if (action === 'bulk_attendance') {
      var sheet = getAttendanceSheet(ss);
      var records = payload.records || [];
      var timestamp = Utilities.formatDate(new Date(), 'Asia/Bangkok', 'yyyy-MM-dd HH:mm:ss');

      for (var k = 0; k < records.length; k++) {
        var r = records[k];
        sheet.appendRow([
          String(r.work_date || ''),
          String(r.employee_code || ''),
          r.full_name || '',
          r.nickname || '',
          r.machine || '',
          r.shift || '',
          r.station || '',
          r.status_label || r.status || 'มาทำงานปกติ',
          Number(r.days_count || 1.0),
          r.remarks || 'บันทึกแบบกลุ่ม',
          timestamp
        ]);
      }
      result.message = 'บันทึกการทำงานแบบกลุ่มจำนวน ' + records.length + ' รายการสำเร็จ';
    }

    return ContentService.createTextOutput(JSON.stringify(result))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (err) {
    return ContentService.createTextOutput(JSON.stringify({ success: false, error: err.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  } finally {
    lock.releaseLock();
  }
}

/**
 * แปลงค่าวันที่ใน Google Sheet ให้เป็นสตริง YYYY-MM-DD มาตรฐาน ป้องกันการส่ง Date Object ISO
 */
function formatGasDate(val) {
  if (!val) return '';
  if (val instanceof Date) {
    return Utilities.formatDate(val, "GMT+7", "yyyy-MM-dd");
  }
  var str = String(val).trim();
  if (str.indexOf('T') !== -1) {
    try {
      var d = new Date(str);
      if (!isNaN(d.getTime())) {
        return Utilities.formatDate(d, "GMT+7", "yyyy-MM-dd");
      }
    } catch(e) {}
  }
  var m = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (m) return m[1] + '-' + m[2] + '-' + m[3];
  return str;
}

/**
 * ดึงข้อมูลพนักงานและการลงเวลาไปแสดงผลที่หน้าเว็บ (GET)
 */
function doGet(e) {
  var ss = SpreadsheetApp.getActiveSpreadsheet();
  var empSheet = getEmployeeSheet(ss);
  var attSheet = getAttendanceSheet(ss);

  var employees = [];
  if (empSheet && empSheet.getLastRow() > 1) {
    var numCols = Math.max(15, empSheet.getLastColumn());
    var data = empSheet.getRange(2, 1, empSheet.getLastRow() - 1, numCols).getValues();
    for (var i = 0; i < data.length; i++) {
      employees.push({
        employee_code: String(data[i][0]),
        title: data[i][1],
        first_name: data[i][2],
        last_name: data[i][3],
        nickname: data[i][4],
        machine: data[i][5],
        shift: data[i][6],
        station: data[i][7],
        position: data[i][8],
        start_date: formatGasDate(data[i][9]),
        birth_date: formatGasDate(data[i][10]),
        phone: data[i][11],
        probation_status: data[i][12],
        status: data[i][13],
        avatar: data[i][14] || '',
        resigned_date: formatGasDate(data[i][15]),
        notes: data[i][16] || ''
      });
    }
  }

  var attendance = [];
  if (attSheet && attSheet.getLastRow() > 1) {
    var aData = attSheet.getRange(2, 1, attSheet.getLastRow() - 1, 10).getValues();
    for (var j = 0; j < aData.length; j++) {
      attendance.push({
        work_date: String(aData[j][0]),
        employee_code: String(aData[j][1]),
        status: aData[j][7],
        days_count: aData[j][8],
        remarks: aData[j][9]
      });
    }
  }

  return ContentService.createTextOutput(JSON.stringify({
    success: true,
    employees: employees,
    attendance: attendance
  })).setMimeType(ContentService.MimeType.JSON);
}

