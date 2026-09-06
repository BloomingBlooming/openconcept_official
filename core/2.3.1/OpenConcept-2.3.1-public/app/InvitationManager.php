<?php

declare(strict_types=1);

final class InvitationManager
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findPending(int $memberId): ?array
    {
        if ($memberId < 1) {
            return null;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, name, email, avatar_color, role, department, must_change_password, invited_at, last_login_at
FROM users
WHERE id = ?
  AND active = 1
  AND must_change_password = 1
  AND last_login_at IS NULL
  AND role <> 'system'
  AND NOT EXISTS (
      SELECT 1 FROM audit_logs
      WHERE action = 'member_account_reactivated'
        AND subject_type = 'user'
        AND subject_id = users.id
  )
SQL);
        $statement->execute([$memberId]);
        $member = $statement->fetch();
        if (!$member) {
            return null;
        }

        $member['id'] = (int) $member['id'];
        $member['must_change_password'] = (bool) $member['must_change_password'];
        $member['initial_login_pending'] = true;
        return $member;
    }

    public function replaceTemporaryPassword(int $memberId, string $passwordHash): ?array
    {
        if ($memberId < 1 || $passwordHash === '') {
            return null;
        }

        $update = $this->pdo->prepare(<<<'SQL'
UPDATE users
SET password_hash = ?, invited_at = CURRENT_TIMESTAMP
WHERE id = ?
  AND active = 1
  AND must_change_password = 1
  AND last_login_at IS NULL
  AND role <> 'system'
  AND NOT EXISTS (
      SELECT 1 FROM audit_logs
      WHERE action = 'member_account_reactivated'
        AND subject_type = 'user'
        AND subject_id = users.id
  )
SQL);
        $update->execute([$passwordHash, $memberId]);
        if ($update->rowCount() !== 1) {
            return null;
        }

        return $this->findPending($memberId);
    }

    public function deletePending(int $memberId): ?array
    {
        if ($memberId < 1) {
            return null;
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $member = $this->findPending($memberId);
            if (!$member) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }
                return null;
            }

            $this->redactEmailFromAuditDetails($memberId);
            $delete = $this->pdo->prepare(<<<'SQL'
DELETE FROM users
WHERE id = ?
  AND active = 1
  AND must_change_password = 1
  AND last_login_at IS NULL
  AND role <> 'system'
  AND NOT EXISTS (
      SELECT 1 FROM audit_logs
      WHERE action = 'member_account_reactivated'
        AND subject_type = 'user'
        AND subject_id = users.id
  )
SQL);
            $delete->execute([$memberId]);
            if ($delete->rowCount() !== 1) {
                if ($ownsTransaction) {
                    $this->pdo->rollBack();
                }
                return null;
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $member;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function redactEmailFromAuditDetails(int $memberId): void
    {
        $select = $this->pdo->prepare("SELECT id, detail_json FROM audit_logs WHERE subject_type = 'user' AND subject_id = ?");
        $select->execute([$memberId]);
        $update = $this->pdo->prepare('UPDATE audit_logs SET detail_json = ? WHERE id = ?');

        foreach ($select->fetchAll() as $row) {
            $detail = json_decode((string) $row['detail_json'], true);
            if (!is_array($detail) || !array_key_exists('email', $detail)) {
                continue;
            }
            unset($detail['email']);
            $update->execute([
                json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int) $row['id'],
            ]);
        }
    }
}
