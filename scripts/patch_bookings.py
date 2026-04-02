"""
Patch admin/bookings.php to add expired booking management.
Run: python scripts/patch_bookings.py
"""
import sys, os

FILE = os.path.join(os.path.dirname(__file__), '..', 'admin', 'bookings.php')

with open(FILE, 'rb') as f:
    raw = f.read()

# Detect line ending
has_crlf = b'\r\n' in raw
# Normalise to LF for matching
content = raw.replace(b'\r\n', b'\n').decode('utf-8')

changes = 0
errors = []

def replace(old, new, label):
    global content, changes
    if old in content:
        content = content.replace(old, new, 1)
        changes += 1
        print(f"  OK: {label}")
    else:
        errors.append(label)
        print(f"  MISSING: {label}")

# 1. Expired confirm guard -----------------------------------------------
replace(
    "                $current_status = $current_booking['status'];\n"
    "                $room_id = $current_booking['room_id'];\n"
    "                \n"
    "                // Update booking status",

    "                $current_status = $current_booking['status'];\n"
    "                $room_id = $current_booking['room_id'];\n"
    "\n"
    "                // Guard: cannot confirm an expired booking (check-out date already past)\n"
    "                if ($new_status === 'confirmed') {\n"
    "                    $exp_stmt = $pdo->prepare(\"SELECT check_out_date FROM bookings WHERE id = ?\");\n"
    "                    $exp_stmt->execute([$booking_id]);\n"
    "                    $exp_row = $exp_stmt->fetch(PDO::FETCH_ASSOC);\n"
    "                    if ($exp_row && strtotime($exp_row['check_out_date']) < strtotime('today')) {\n"
    "                        throw new Exception('Cannot confirm an expired booking - the check-out date has already passed.');\n"
    "                    }\n"
    "                }\n"
    "                \n"
    "                // Update booking status",
    "1. Expired confirm guard"
)

# 2. delete_booking action handler ----------------------------------------
replace(
    "                } else {\n"
    "                    $error = 'Only confirmed bookings can be marked as no-show.';\n"
    "                }\n"
    "            }\n"
    "        }\n"
    "\n"
    "    } catch (Throwable $e) {",

    "                } else {\n"
    "                    $error = 'Only confirmed bookings can be marked as no-show.';\n"
    "                }\n"
    "            }\n"
    "        } elseif ($action === 'delete_booking') {\n"
    "            // Only super admin can delete expired bookings\n"
    "            if ($user['role'] !== 'admin') {\n"
    "                throw new Exception('Only the super admin can delete bookings.');\n"
    "            }\n"
    "            $booking_id = (int)($_POST['id'] ?? 0);\n"
    "            if ($booking_id <= 0) {\n"
    "                throw new Exception('Invalid booking id.');\n"
    "            }\n"
    "            $exp_stmt = $pdo->prepare(\"SELECT check_out_date, booking_reference FROM bookings WHERE id = ?\");\n"
    "            $exp_stmt->execute([$booking_id]);\n"
    "            $exp_row = $exp_stmt->fetch(PDO::FETCH_ASSOC);\n"
    "            if (!$exp_row) {\n"
    "                throw new Exception('Booking not found.');\n"
    "            }\n"
    "            if (strtotime($exp_row['check_out_date']) >= strtotime('today')) {\n"
    "                throw new Exception('Only expired bookings (past check-out date) can be deleted.');\n"
    "            }\n"
    "            $del_stmt = $pdo->prepare(\"DELETE FROM bookings WHERE id = ?\");\n"
    "            $del_stmt->execute([$booking_id]);\n"
    "            $message = 'Expired booking ' . htmlspecialchars($exp_row['booking_reference']) . ' deleted successfully.';\n"
    "        }\n"
    "\n"
    "    } catch (Throwable $e) {",
    "2. delete_booking handler"
)

# 3. is_expired variable + row styling ------------------------------------
replace(
    "                            ?>\n"
    "                            <tr <?php echo $is_tentative ? 'style=\"background: linear-gradient(90deg, rgba(212, 175, 55, 0.05) 0%, white 10%);\"' : ''; ?>>",

    "                                // Expired: check-out date past, booking not yet finalized\n"
    "                                $is_expired = !in_array($booking['status'], ['checked-out', 'cancelled', 'no-show'])\n"
    "                                    && strtotime($booking['check_out_date']) < strtotime('today');\n"
    "                            ?>\n"
    "                            <tr <?php\n"
    "                                if ($is_expired) echo 'style=\"opacity:0.6; background-color:#f0f0f0 !important;\"';\n"
    "                                elseif ($is_tentative) echo 'style=\"background: linear-gradient(90deg, rgba(212, 175, 55, 0.05) 0%, white 10%);\"';\n"
    "                            ?>>",
    "3. is_expired var + row styling"
)

# 4. Expired badge in status column --------------------------------------
replace(
    "                                <td>\n"
    "                                    <span class=\"badge badge-<?php echo $booking['status']; ?>\">\n"
    "                                        <?php echo ucfirst($booking['status']); ?>\n"
    "                                    </span>\n"
    "                                    <?php if ($is_tentative && $booking['tentative_expires_at']): ?>",

    "                                <td>\n"
    "                                    <span class=\"badge badge-<?php echo $booking['status']; ?>\">\n"
    "                                        <?php echo ucfirst($booking['status']); ?>\n"
    "                                    </span>\n"
    "                                    <?php if ($is_expired): ?>\n"
    "                                        <br><span style=\"background:#6c757d;color:white;font-size:10px;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:3px;\">Expired</span>\n"
    "                                    <?php endif; ?>\n"
    "                                    <?php if ($is_tentative && $booking['tentative_expires_at']): ?>",
    "4. Expired badge"
)

# 5. Block Confirm/Make-Tentative for expired ----------------------------
replace(
    "                                    <?php elseif ($booking['status'] === 'pending'): ?>\n"
    "                                        <button class=\"quick-action confirm\" onclick=\"updateStatus(<?php echo $booking['id']; ?>, 'confirmed')\">\n"
    "                                            <i class=\"fas fa-check\"></i> Confirm\n"
    "                                        </button>\n"
    "                                        <button class=\"quick-action\" style=\"background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%); color: var(--deep-navy);\" onclick=\"makeTentative(<?php echo $booking['id']; ?>)\">\n"
    "                                            <i class=\"fas fa-clock\"></i> Make Tentative\n"
    "                                        </button>\n"
    "                                        <button class=\"quick-action cancel\" onclick=\"cancelBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')\">\n",

    "                                    <?php elseif ($booking['status'] === 'pending'): ?>\n"
    "                                        <?php if (!$is_expired): ?>\n"
    "                                        <button class=\"quick-action confirm\" onclick=\"updateStatus(<?php echo $booking['id']; ?>, 'confirmed')\">\n"
    "                                            <i class=\"fas fa-check\"></i> Confirm\n"
    "                                        </button>\n"
    "                                        <button class=\"quick-action\" style=\"background: linear-gradient(135deg, var(--gold) 0%, #c49b2e 100%); color: var(--deep-navy);\" onclick=\"makeTentative(<?php echo $booking['id']; ?>)\">\n"
    "                                            <i class=\"fas fa-clock\"></i> Make Tentative\n"
    "                                        </button>\n"
    "                                        <?php endif; ?>\n"
    "                                        <button class=\"quick-action cancel\" onclick=\"cancelBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')\">\n",
    "5. Block confirm for expired"
)

# 6. Add Delete button for super admin -----------------------------------
replace(
    "                                    <?php if ($booking['payment_status'] !== 'paid'): ?>\n"
    "                                        <button class=\"quick-action paid\" onclick=\"updatePayment(<?php echo $booking['id']; ?>, 'paid')\">\n"
    "                                            <i class=\"fas fa-dollar-sign\"></i> Paid\n"
    "                                        </button>\n"
    "                                    <?php endif; ?>\n"
    "                                </td>",

    "                                    <?php if ($booking['payment_status'] !== 'paid'): ?>\n"
    "                                        <button class=\"quick-action paid\" onclick=\"updatePayment(<?php echo $booking['id']; ?>, 'paid')\">\n"
    "                                            <i class=\"fas fa-dollar-sign\"></i> Paid\n"
    "                                        </button>\n"
    "                                    <?php endif; ?>\n"
    "                                    <?php if ($is_expired && $user['role'] === 'admin'): ?>\n"
    "                                        <button class=\"quick-action\" style=\"background:#dc3545;color:white;\" onclick=\"deleteBooking(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['booking_reference'], ENT_QUOTES); ?>')\">\n"
    "                                            <i class=\"fas fa-trash\"></i> Delete\n"
    "                                        </button>\n"
    "                                    <?php endif; ?>\n"
    "                                </td>",
    "6. Delete button for super admin"
)

# 7. Conference inquiries: expired row ------------------------------------
replace(
    "                        <?php foreach ($conference_inquiries as $inquiry): ?>\n"
    "                            <tr>\n"
    "                                <td><?php echo date('M d, Y', strtotime($inquiry['created_at'])); ?></td>",

    "                        <?php foreach ($conference_inquiries as $inquiry): ?>\n"
    "                            <?php $is_conf_expired = !empty($inquiry['event_date']) && strtotime($inquiry['event_date']) < strtotime('today'); ?>\n"
    "                            <tr<?php if ($is_conf_expired) echo ' style=\"opacity:0.6;background-color:#f0f0f0 !important;\"'; ?>>\n"
    "                                <td><?php echo date('M d, Y', strtotime($inquiry['created_at'])); ?></td>",
    "7. Conference expired row"
)

# 8. Conference date cell: fix expected_date -> event_date + badge -------
replace(
    "                                <td><?php echo date('M d, Y', strtotime($inquiry['expected_date'])); ?></td>",

    "                                <td>\n"
    "                                    <?php echo !empty($inquiry['event_date']) ? date('M d, Y', strtotime($inquiry['event_date'])) : '&mdash;'; ?>\n"
    "                                    <?php if ($is_conf_expired): ?>\n"
    "                                        <br><span style=\"background:#6c757d;color:white;font-size:10px;padding:2px 6px;border-radius:4px;display:inline-block;margin-top:2px;\">Expired</span>\n"
    "                                    <?php endif; ?>\n"
    "                                </td>",
    "8. Conference date + badge"
)

# 9. deleteBooking JS function -------------------------------------------
replace(
    "                Alert.show('Error marking as no-show', 'error');\n"
    "            });\n"
    "        }\n"
    "    </script>",

    "                Alert.show('Error marking as no-show', 'error');\n"
    "            });\n"
    "        }\n"
    "\n"
    "        function deleteBooking(id, reference) {\n"
    "            if (!confirm('PERMANENTLY delete expired booking ' + reference + '?\\n\\nThis action cannot be undone.')) return;\n"
    "            var form = document.createElement('form');\n"
    "            form.method = 'POST';\n"
    "            form.action = window.location.href;\n"
    "            [['action', 'delete_booking'], ['id', id]].forEach(function(pair) {\n"
    "                var input = document.createElement('input');\n"
    "                input.type = 'hidden';\n"
    "                input.name = pair[0];\n"
    "                input.value = pair[1];\n"
    "                form.appendChild(input);\n"
    "            });\n"
    "            document.body.appendChild(form);\n"
    "            form.submit();\n"
    "        }\n"
    "    </script>",
    "9. deleteBooking JS"
)

print(f"\nTotal: {changes}/9 changes applied")
if errors:
    print("MISSING targets:")
    for e in errors:
        print(f"  - {e}")

# Restore CRLF if needed, then write
out = content.encode('utf-8')
if has_crlf:
    out = out.replace(b'\n', b'\r\n')

with open(FILE, 'wb') as f:
    f.write(out)

print("File written.")
sys.exit(0 if not errors else 1)
