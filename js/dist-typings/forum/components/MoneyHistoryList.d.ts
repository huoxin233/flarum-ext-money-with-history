import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type UserMoneyHistory from '../models/UserMoneyHistory';
interface MoneyHistoryListAttrs extends ComponentAttrs {
    params: {
        user: User;
    };
}
export default class MoneyHistoryList extends Component<MoneyHistoryListAttrs> {
    loading: boolean;
    moreResults: boolean;
    historyEntries: UserMoneyHistory[];
    user: User;
    oninit(vnode: Mithril.Vnode<MoneyHistoryListAttrs>): void;
    view(): Mithril.Children;
    loadMore(): void;
    parseResults(historyEntries: UserMoneyHistory[] & {
        payload?: {
            links?: {
                next?: string;
            };
        };
    }): UserMoneyHistory[];
    hasMoreResults(): boolean;
    loadResults(offset?: number): Promise<UserMoneyHistory[]>;
}
export {};
