export async function graphql(endpoint, csrf, query, variables, operationName) {
    let response;
    try {
        response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            body: JSON.stringify({ query, variables, operationName }),
            signal: AbortSignal.timeout(15000),
        });
    } catch {
        throw Object.assign(new Error('通信できませんでした。保存済みの可能性があるため、一覧を再取得して確認してください。'), { uncertain: true });
    }
    if ([401, 419].includes(response.status)) {
        throw Object.assign(new Error('ログインの有効期限が切れました。もう一度ログインしてください。'), { loginRequired: true });
    }
    if (!response.ok) {
        throw Object.assign(new Error('処理に失敗しました。一覧を再取得して確認してください。'), { uncertain: true });
    }
    let result;
    try {
        result = await response.json();
    } catch {
        throw Object.assign(new Error('応答を確認できませんでした。一覧を再取得してください。'), { uncertain: true });
    }
    if (result.errors?.length) {
        const first = result.errors[0];
        throw Object.assign(new Error(['NOT_FOUND', 'FORBIDDEN', 'BUSY', 'CONFLICT'].includes(first.extensions?.code)
            ? first.message : first.extensions?.validation
                ? '入力内容を確認してください。' : '処理に失敗しました。一覧を再取得して確認してください。'), {
            validation: first.extensions?.validation || {},
            code: first.extensions?.code,
            uncertain: !['NOT_FOUND', 'VALIDATION_FAILED', 'FORBIDDEN', 'BUSY', 'CONFLICT'].includes(first.extensions?.code),
        });
    }
    if (!result.data) {
        throw Object.assign(new Error('応答を確認できませんでした。一覧を再取得してください。'), { uncertain: true });
    }
    return result.data;
}

export const taskFields = 'id folder_id title status due_date edit_url delete_url';
