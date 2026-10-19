/**
 * =========================================================================
 * Google Apps Script: Waste Production Webhook
 * =========================================================================
 * 
 * วิธีการติดตั้งลงใน Google Sheet:
 * 1. เปิดไฟล์ Google Sheet ของคุณ
 * 2. ไปที่เมนู "ส่วนขยาย" (Extensions) > "Apps Script"
 * 3. ลบโค้ดเดิมออกทั้งหมด แล้ววางโค้ดชุดนี้ลงไป
 * 4. กดปุ่มบันทึก (รูปแผ่นดิสก์)
 * 5. กดปุ่มสีน้ำเงินด้านขวาบน "การทำให้ใช้งานได้" (Deploy) > "การทำให้ใช้งานได้รายการใหม่" (New deployment)
 * 6. เลือกประเภทเป็น "เว็บแอปพลิเคชัน" (Web app)
 * 7. ตั้งค่า:
 *    - คำอธิบาย: Waste Production Webhook
 *    - ปฏิบัติการในฐานะ: ตัวฉัน (Me)
 *    - ผู้ที่มีสิทธิ์เข้าถึง: ทุกคน (Anyone)  <-- สำคัญมาก! เพื่อให้ส่งข้อมูลเข้ามาได้
 * 8. กดปุ่ม "ทำให้ใช้งานได้" (Deploy) แล้วคัดลอก "URL เว็บแอป" (Web App URL)
 * 9. นำ URL ที่ได้ไปวางในช่อง "Google Apps Script Web App URL" ในหน้านำเข้าไฟล์ (upload.html)
 * =========================================================================
 */

function doPost(e) {
  var lock = LockService.getScriptLock();
  lock.waitLock(30000); // ป้องกันการเขียนชนกัน รอคิวไม่เกิน 30 วินาที

  try {
    if (!e || !e.postData || !e.postData.contents) {
      return ContentService.createTextOutput(JSON.stringify({ success: false, error: 'No payload received' }))
        .setMimeType(ContentService.MimeType.JSON);
    }

    var payload = JSON.parse(e.postData.contents);
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var action = payload.action;
    var result = { success: true, message: 'บันทึกข้อมูลเรียบร้อยแล้ว' };

    // 1. อัปเดตข้อมูล Consumption & Output รายวันลง Sheet "con out"
    if (action === 'update_con_out') {
      var sheet = ss.getSheetByName('con out') || ss.getSheets()[0];
      var rows = payload.rows || [];
      var lastRow = sheet.getLastRow();
      var dataRange = lastRow > 1 ? sheet.getRange(2, 1, lastRow - 1, 25).getValues() : [];

      rows.forEach(function(item) {
        var foundRow = -1;
        for (var i = 0; i < dataRange.length; i++) {
          var cellDate = String(dataRange[i][0]).trim();
          if (cellDate.indexOf(item.date) !== -1 || item.date.indexOf(cellDate) !== -1) {
            foundRow = i + 2;
            break;
          }
        }

        var targetRow = foundRow > 0 ? foundRow : sheet.getLastRow() + 1;
        sheet.getRange(targetRow, 1).setValue(item.date);

        // BHS: Col 8 (H) = Consumption, Col 9 (I) = Output
        if (item.bhs_consum !== undefined && item.bhs_consum !== '') {
          sheet.getRange(targetRow, 8).setValue(Number(item.bhs_consum));
        }
        if (item.bhs_output !== undefined && item.bhs_output !== '') {
          sheet.getRange(targetRow, 9).setValue(Number(item.bhs_output));
        }

        // YUELI: Col 14 (N) = Consumption, Col 15 (O) = Output
        if (item.yueli_consum !== undefined && item.yueli_consum !== '') {
          sheet.getRange(targetRow, 14).setValue(Number(item.yueli_consum));
        }
        if (item.yueli_output !== undefined && item.yueli_output !== '') {
          sheet.getRange(targetRow, 15).setValue(Number(item.yueli_output));
        }

        // ISOWA: Col 2 (B) = Consumption, Col 3 (C) = Output
        if (item.isowa_consum !== undefined && item.isowa_consum !== '') {
          sheet.getRange(targetRow, 2).setValue(Number(item.isowa_consum));
        }
        if (item.isowa_output !== undefined && item.isowa_output !== '') {
          sheet.getRange(targetRow, 3).setValue(Number(item.isowa_output));
        }
      });

      result.updatedCount = rows.length;
    }

    // 2. อัปเดตข้อมูลของเสีย 11 รายการลง Sheet "Waste "
    else if (action === 'update_waste') {
      var sheet = ss.getSheetByName('Waste ') || ss.getSheetByName('Waste') || ss.insertSheet('Waste ');
      var dateStr = payload.date; // เช่น "19/10/2026"
      var machine = (payload.machine || 'BHS').toUpperCase();
      var items = payload.items || []; // [{ code: 'BL18', name: 'NCP', kg: 1500 }, ...]

      // ค้นหาว่ามีวันที่นี้บันทึกไว้หรือยัง หรือเขียนต่อท้าย
      var lastRow = sheet.getLastRow();
      var startRow = lastRow + 1;

      // เพิ่มหัวข้อวันที่และเครื่อง
      sheet.getRange(startRow, 1).setValue(dateStr);
      sheet.getRange(startRow, 2).setValue(machine);
      sheet.getRange(startRow, 3).setValue('--- บันทึกข้อมูลของเสีย 11 รายการ ---');

      var nextRow = startRow + 1;
      items.forEach(function(it) {
        if (Number(it.kg) > 0) {
          sheet.getRange(nextRow, 1).setValue(dateStr);
          sheet.getRange(nextRow, 2).setValue(machine);
          sheet.getRange(nextRow, 3).setValue(it.code);
          sheet.getRange(nextRow, 4).setValue(it.name);
          sheet.getRange(nextRow, 5).setValue(Number(it.kg));
          nextRow++;
        }
      });

      result.wasteCount = items.length;
    }

    return ContentService.createTextOutput(JSON.stringify(result))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({ success: false, error: error.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  } finally {
    lock.releaseLock();
  }
}

function doGet(e) {
  return ContentService.createTextOutput(JSON.stringify({
    status: 'online',
    message: 'Waste Production Webhook พร้อมรับข้อมูลจาก Dashboard'
  })).setMimeType(ContentService.MimeType.JSON);
}
