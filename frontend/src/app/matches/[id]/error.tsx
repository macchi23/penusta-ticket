"use client";

import { useEffect } from "react";
import Link from "next/link";

export default function MatchError({
  error,
  retry,
}: {
  error: Error & { digest?: string };
  retry: () => void;
}) {
  useEffect(() => {
    console.error(error);
  }, [error]);
  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <p>ページを表示できませんでした。</p>
      <div className="mt-3 flex gap-4">
        <button type="button" onClick={() => retry()} className="text-blue-600 underline">
          再試行
        </button>
        <Link href="/" className="text-blue-600 underline">
          試合一覧へ戻る
        </Link>
      </div>
    </main>
  );
}
