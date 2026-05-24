import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';
export default class UserMoneyHistory extends Model {
    balanceDelta: () => number;
    source: () => string;
    sourceKey: () => string | null;
    sourceParams: () => Record<string, unknown> | null;
    createdAt: () => Date;
    balanceBefore: () => number;
    balanceAfter: () => number;
    user: () => false | User;
    actor: () => false | User;
}
