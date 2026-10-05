import Link from "next/link";

export default function Unauthorized() {
  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <p>ユーザーが選択されていません。画面上部のユーザー切替で管理者を選んでください。</p>
      <Link href="/" className="text-blue-600 underline">
        試合一覧へ戻る
      </Link>
    </main>
  );
}
