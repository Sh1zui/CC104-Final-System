<?php
/**
 * Several suites were written against the original, fixed-date sample
 * bookings (September 2026). The shipped seed is now relative to the import
 * date so the demo always looks right; this puts those two bookings, their
 * release, and their payments back on the fixed dates the suites expect.
 */
function use_fixed_sample_bookings(PDO $db): void
{
    $db->exec("UPDATE bookings SET pickup_datetime = '2026-09-18 09:00:00', return_datetime = '2026-09-22 09:00:00',
                      rental_days = 4, base_amount = 12800.00, total_amount = 14800.00,
                      confirmed_at = '2026-09-17 15:20:00', created_at = '2026-09-17 15:12:00'
               WHERE booking_id = 1");
    $db->exec("UPDATE bookings SET pickup_datetime = '2026-09-25 10:00:00', return_datetime = '2026-09-27 10:00:00',
                      confirmed_at = '2026-09-20 11:05:00', created_at = '2026-09-20 10:41:00'
               WHERE booking_id = 2");
    $db->exec("UPDATE rentals SET released_at = '2026-09-18 09:15:00' WHERE booking_id = 1");
    $db->exec('DELETE FROM payments');
    $db->exec("INSERT INTO payments (payment_id, booking_id, receipt_number, amount, payment_type, payment_method, transaction_ref, status, recorded_by, paid_at) VALUES
        (1, 1, 'OR-2026-000001', 2000.00, 'deposit',    'cash',  NULL,            'completed', 2, '2026-09-17 15:25:00'),
        (2, 1, 'OR-2026-000002', 6400.00, 'rental_fee', 'gcash', 'GC-8841203917', 'completed', 2, '2026-09-18 09:10:00'),
        (3, 2, 'OR-2026-000003', 9100.00, 'rental_fee', 'card',  'CARD-55129034', 'completed', 1, '2026-09-20 11:06:00')");
    $db->exec('ALTER TABLE payments AUTO_INCREMENT = 4');
    $db->exec("UPDATE maintenance SET service_date = '2026-09-15' WHERE maintenance_id = 4");
}
